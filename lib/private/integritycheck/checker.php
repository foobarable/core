<?php
/**
 * @author Lukas Reschke <lukas@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\IntegrityCheck;

use OC\IntegrityCheck\Exceptions\InvalidSignatureException;
use OC\IntegrityCheck\Helpers\AppLocator;
use OC\IntegrityCheck\Helpers\EnvironmentHelper;
use OC\IntegrityCheck\Helpers\FileAccessHelper;
use OC\Integritycheck\Iterator\ExcludeFileByNameFilterIterator;
use OC\IntegrityCheck\Iterator\ExcludeFoldersByPathFilterIterator;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use phpseclib\Crypt\RSA;
use phpseclib\File\X509;

/**
 * Class Checker handles the code signing using X.509 and RSA. ownCloud ships with
 * a public root certificate certificate that allows to issue new certificates that
 * will be trusted for signing code. The CN will be used to verify that a certificate
 * given to a third-party developer may not be used for other applications. For
 * example the author of the application "calendar" would only receive a certificate
 * only valid for this application.
 *
 * @package OC\IntegrityCheck
 */
class Checker {
	const CACHE_KEY = 'oc.integritycheck.checker';
	/** @var EnvironmentHelper */
	private $environmentHelper;
	/** @var AppLocator */
	private $appLocator;
	/** @var FileAccessHelper */
	private $fileAccessHelper;
	/** @var IConfig */
	private $config;
	/** @var ICache */
	private $cache;
	/** @var IAppManager */
	private $appManager;

	/**
	 * @param EnvironmentHelper $environmentHelper
	 * @param FileAccessHelper $fileAccessHelper
	 * @param AppLocator $appLocator
	 * @param IConfig $config
	 * @param ICacheFactory $cacheFactory
	 * @param IAppManager $appManager
	 */
	public function __construct(EnvironmentHelper $environmentHelper,
								FileAccessHelper $fileAccessHelper,
								AppLocator $appLocator,
								IConfig $config = null,
								ICacheFactory $cacheFactory,
								IAppManager $appManager = null) {
		$this->environmentHelper = $environmentHelper;
		$this->fileAccessHelper = $fileAccessHelper;
		$this->appLocator = $appLocator;
		$this->config = $config;
		$this->cache = $cacheFactory->create(self::CACHE_KEY);
		$this->appManager = $appManager;
	}

	/**
	 * Enumerates all files belonging to the folder. Sensible defaults are excluded.
	 *
	 * @param string $folderToIterate
	 * @return \RecursiveIteratorIterator
	 */
	private function getFolderIterator($folderToIterate) {
		$dirItr = new \RecursiveDirectoryIterator(
			$folderToIterate,
			\RecursiveDirectoryIterator::SKIP_DOTS
		);
		$excludeGenericFilesIterator = new ExcludeFileByNameFilterIterator($dirItr);
		$excludeFoldersIterator = new ExcludeFoldersByPathFilterIterator($excludeGenericFilesIterator);

		return new \RecursiveIteratorIterator(
			$excludeFoldersIterator,
			\RecursiveIteratorIterator::SELF_FIRST
		);
	}

	/**
	 * Returns an array of ['filename' => 'SHA512-hash-of-file'] for all files found
	 * in the iterator.
	 *
	 * @param \RecursiveIteratorIterator $iterator
	 * @param string $path
	 * @return array Array of hashes.
	 */
	private function generateHashes(\RecursiveIteratorIterator $iterator,
									$path) {
		$hashes = [];

		$baseDirectoryLength = strlen($path);
		foreach($iterator as $filename => $data) {
			/** @var \DirectoryIterator $data */
			if($data->isDir()) {
				continue;
			}

			$relativeFileName = substr($filename, $baseDirectoryLength);

			// Exclude signature.json files in the appinfo and root folder
			if($relativeFileName === '/appinfo/signature.json') {
				continue;
			}
			// Exclude signature.json files in the appinfo and core folder
			if($relativeFileName === '/core/signature.json') {
				continue;
			}

			$hashes[$relativeFileName] = hash_file('sha512', $filename);
		}
		return $hashes;
	}

	/**
	 * Creates the signature data
	 *
	 * @param array $hashes
	 * @param X509 $certificate
	 * @param RSA $privateKey
	 * @return string
	 */
	private function createSignatureData(array $hashes,
										 X509 $certificate,
										 RSA $privateKey) {
		ksort($hashes);

		$privateKey->setSignatureMode(RSA::SIGNATURE_PSS);
		$privateKey->setMGFHash('sha512');
		$signature = $privateKey->sign(json_encode($hashes));

		return [
				'hashes' => $hashes,
				'signature' => base64_encode($signature),
				'certificate' => $certificate->saveX509($certificate->currentCert),
			];
	}

	/**
	 * Write the signature of the specified app
	 *
	 * @param string $appId
	 * @param X509 $certificate
	 * @param RSA $privateKey
	 * @throws \Exception
	 */
	public function writeAppSignature($appId,
									  X509 $certificate,
									  RSA $privateKey) {
		$path = $this->appLocator->getAppPath($appId);
		$iterator = $this->getFolderIterator($path);
		$hashes = $this->generateHashes($iterator, $path);
		$signature = $this->createSignatureData($hashes, $certificate, $privateKey);
		$this->fileAccessHelper->file_put_contents(
				$path . '/appinfo/signature.json',
				json_encode($signature, JSON_PRETTY_PRINT)
		);
	}

	/**
	 * Write the signature of core
	 *
	 * @param X509 $certificate
	 * @param RSA $rsa
	 */
	public function writeCoreSignature(X509 $certificate,
									   RSA $rsa) {
		$iterator = $this->getFolderIterator($this->environmentHelper->getServerRoot());
		$hashes = $this->generateHashes($iterator, $this->environmentHelper->getServerRoot());
		$signatureData = $this->createSignatureData($hashes, $certificate, $rsa);
		$this->fileAccessHelper->file_put_contents(
				$this->environmentHelper->getServerRoot() . '/core/signature.json',
				json_encode($signatureData, JSON_PRETTY_PRINT)
		);
	}

	/**
	 * Verifies the signature for the specified path.
	 *
	 * @param string $signaturePath
	 * @param string $basePath
	 * @param string $certificateCN
	 * @return array
	 * @throws InvalidSignatureException
	 * @throws \Exception
	 */
	private function verify($signaturePath, $basePath, $certificateCN) {
		$signatureData = json_decode($this->fileAccessHelper->file_get_contents($signaturePath), true);
		if(!is_array($signatureData)) {
			throw new InvalidSignatureException('Signature data not found.');
		}

		$expectedHashes = $signatureData['hashes'];
		ksort($expectedHashes);
		$signature = base64_decode($signatureData['signature']);
		$certificate = $signatureData['certificate'];

		// Check if certificate is signed by ownCloud Root Authority
		$x509 = new \phpseclib\File\X509();
		$rootCertificatePublicKey = $this->fileAccessHelper->file_get_contents($this->environmentHelper->getServerRoot().'/resources/codesigning/root.crt');
		$x509->loadCA($rootCertificatePublicKey);
		$x509->loadX509($certificate);
		if(!$x509->validateSignature()) {
			throw new InvalidSignatureException('Certificate is not valid.');
		}
		// Verify if certificate has proper CN. "core" CN is always trusted.
		if($x509->getDN(X509::DN_OPENSSL)['CN'] !== $certificateCN && $x509->getDN(X509::DN_OPENSSL)['CN'] !== 'core') {
			throw new InvalidSignatureException(
					sprintf('Certificate is not valid for required scope. (Requested: %s, current: %s)', $certificateCN, $x509->getDN(true))
			);
		}

		// Check if the signature of the files is valid
		$rsa = new \phpseclib\Crypt\RSA();
		$rsa->loadKey($x509->currentCert['tbsCertificate']['subjectPublicKeyInfo']['subjectPublicKey']);
		$rsa->setSignatureMode(RSA::SIGNATURE_PSS);
		$rsa->setMGFHash('sha512');
		if(!$rsa->verify(json_encode($expectedHashes), $signature)) {
			throw new InvalidSignatureException('Signature could not get verified.');
		}

		// Compare the list of files which are not identical
		$currentInstanceHashes = $this->generateHashes($this->getFolderIterator($basePath), $basePath);
		$differencesA = array_diff($expectedHashes, $currentInstanceHashes);
		$differencesB = array_diff($currentInstanceHashes, $expectedHashes);
		$differences = array_unique(array_merge($differencesA, $differencesB));
		$differenceArray = [];
		foreach($differences as $filename => $hash) {
			// Check if file should not exist in the new signature table
			if(!array_key_exists($filename, $expectedHashes)) {
				$differenceArray['EXTRA_FILE'][$filename]['expected'] = '';
				$differenceArray['EXTRA_FILE'][$filename]['current'] = $hash;
				continue;
			}

			// Check if file is missing
			if(!array_key_exists($filename, $currentInstanceHashes)) {
				$differenceArray['FILE_MISSING'][$filename]['expected'] = $expectedHashes[$filename];
				$differenceArray['FILE_MISSING'][$filename]['current'] = '';
				continue;
			}

			// Check if hash does mismatch
			if($expectedHashes[$filename] !== $currentInstanceHashes[$filename]) {
				$differenceArray['INVALID_HASH'][$filename]['expected'] = $expectedHashes[$filename];
				$differenceArray['INVALID_HASH'][$filename]['current'] = $currentInstanceHashes[$filename];
				continue;
			}

			// Should never happen.
			throw new \Exception('Invalid behaviour in file hash comparison experienced. Please report this error to the developers.');
		}

		return $differenceArray;
	}

	/**
	 * Whether the code integrity check has passed successful or not
	 *
	 * @return bool
	 */
	public function hasPassedCheck() {
		$results = $this->getResults();
		if(empty($results)) {
			return true;
		}

		return false;
	}

	/**
	 * @return array
	 */
	public function getResults() {
		$cachedResults = $this->cache->get(self::CACHE_KEY);
		if(!is_null($cachedResults)) {
			return json_decode($cachedResults, true);
		}

		return json_decode($this->config->getAppValue('core', self::CACHE_KEY, '{}'), true);
	}

	/**
	 * Stores the results in the app config as well as cache
	 *
	 * @param string $scope
	 * @param array $result
	 */
	private function storeResults($scope, array $result) {
		$resultArray = $this->getResults();
		unset($resultArray[$scope]);
		if(!empty($result)) {
			$resultArray[$scope] = $result;
		}
		$this->config->setAppValue('core', self::CACHE_KEY, json_encode($resultArray));
		$this->cache->set(self::CACHE_KEY, json_encode($resultArray));
	}


	/**
	 * Verify the signature of $appId. Returns an array with the following content:
	 * [
	 * 	'FILE_MISSING' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'EXTRA_FILE' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'INVALID_HASH' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * ]
	 *
	 * Array may be empty in case no problems have been found.
	 *
	 * @param string $appId
	 * @return array
	 */
	public function verifyAppSignature($appId) {
		try {
			$path = $this->appLocator->getAppPath($appId);
			$result = $this->verify(
					$path . '/appinfo/signature.json',
					$path,
					$appId
			);
		} catch (\Exception $e) {
			$result = [
					'EXCEPTION' => [
							'class' => get_class($e),
							'message' => $e->getMessage(),
					],
			];
		}
		$this->storeResults($appId, $result);

		return $result;
	}

	/**
	 * Verify the signature of core. Returns an array with the following content:
	 * [
	 * 	'FILE_MISSING' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'EXTRA_FILE' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * 	'INVALID_HASH' =>
	 * 	[
	 * 		'filename' => [
	 * 			'expected' => 'expectedSHA512',
	 * 			'current' => 'currentSHA512',
	 * 		],
	 * 	],
	 * ]
	 *
	 * Array may be empty in case no problems have been found.
	 *
	 * @return array
	 */
	public function verifyCoreSignature() {
		try {
			$result = $this->verify(
					$this->environmentHelper->getServerRoot() . '/core/signature.json',
					$this->environmentHelper->getServerRoot(),
					'core'
			);
		} catch (\Exception $e) {
			$result = [
					'EXCEPTION' => [
							'class' => get_class($e),
							'message' => $e->getMessage(),
					],
			];
		}
		$this->storeResults('core', $result);

		return $result;
	}

	/**
	 * Verify the core code of the instance as well as all applicable applications
	 * and store the results.
	 */
	public function runInstanceVerification() {
		$this->verifyCoreSignature();
		$appIds = $this->appLocator->getAllApps();
		foreach($appIds as $appId) {
			// If an application is shipped a valid signature is required
			$isShipped = $this->appManager->isShipped($appId);
			$appNeedsToBeChecked = false;
			if ($isShipped) {
				$appNeedsToBeChecked = true;
			} elseif ($this->fileAccessHelper->file_exists($this->appLocator->getAppPath($appId) . '/appinfo/signature.json')) {
				// Otherwise only if the application explicitly ships a signature.json file
				$appNeedsToBeChecked = true;
			}

			if($appNeedsToBeChecked) {
				$this->verifyAppSignature($appId);
			}
		}
	}
}
