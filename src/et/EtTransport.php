<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\et;

use Craft;
use craft\enums\LicenseKeyStatus;
use craft\errors\EtException;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\models\Et as EtModel;
use craft\services\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;
use yii\base\Exception;

/**
 * Class Et
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class EtTransport
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    private $_endpoint;

    /**
     * @var int
     */
    private $_timeout;

    /**
     * @var EtModel
     */
    private $_model;

    /**
     * @var bool
     */
    private $_allowRedirects = true;

    /**
     * @var string
     */
    private $_userAgent;

    // Public Methods
    // =========================================================================

    /**
     * @param         $endpoint
     * @param integer $timeout
     * @param integer $connectTimeout
     */
    public function __construct($endpoint, $timeout = 30, $connectTimeout = 30)
    {
        $endpoint .= Craft::$app->getConfig()->get('endpointSuffix');

        $this->_endpoint = $endpoint;
        $this->_timeout = $timeout;
        $this->_connectTimeout = $connectTimeout;

        // There can be a race condition after an update from older Craft versions where they lose session
        // and another call to elliott is made during cleanup.
        $user = Craft::$app->getUser()->getIdentity();
        $userEmail = $user ? $user->email : '';

        $this->_model = new EtModel([
            'licenseKey' => $this->_getLicenseKey(),
            'pluginLicenseKeys' => $this->_getPluginLicenseKeys(),
            'requestUrl' => Craft::$app->getRequest()->getAbsoluteUrl(),
            'requestIp' => Craft::$app->getRequest()->getUserIP(),
            'requestTime' => DateTimeHelper::currentTimeStamp(),
            'requestPort' => Craft::$app->getRequest()->getPort(),
            'localVersion' => Craft::$app->version,
            'localEdition' => Craft::$app->getEdition(),
            'userEmail' => $userEmail,
            'showBeta' => Craft::$app->getConfig()->get('showBetaUpdates'),
            'serverInfo' => [
                'extensions' => get_loaded_extensions(),
                'phpVersion' => PHP_VERSION,
                'databaseType' => Craft::$app->getConfig()->get('driver', Config::CATEGORY_DB),
                'databaseVersion' => Craft::$app->getDb()->pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'proc' => function_exists('proc_open') ? 1 : 0,
            ],
        ]);

        $this->_userAgent = 'Craft/'.Craft::$app->version;
    }

    /**
     * The maximum number of seconds to allow for an entire transfer to take place before timing out.  Set 0 to wait
     * indefinitely.
     *
     * @return integer
     */
    public function getTimeout()
    {
        return $this->_timeout;
    }

    /**
     * The maximum number of seconds to wait while trying to connect. Set to 0 to wait indefinitely.
     *
     * @return integer
     */
    public function getConnectTimeout()
    {
        return $this->_connectTimeout;
    }

    /**
     * Whether or not to follow redirects on the request.  Defaults to true.
     *
     * @param $allowRedirects
     *
     * @return void
     */
    public function setAllowRedirects($allowRedirects)
    {
        $this->_allowRedirects = $allowRedirects;
    }

    /**
     * @return boolean
     */
    public function getAllowRedirects()
    {
        return $this->_allowRedirects;
    }

    /**
     * @return EtModel
     */
    public function getModel()
    {
        return $this->_model;
    }

    /**
     * Sets custom data on the EtModel.
     *
     * @param $data
     *
     * @return void
     */
    public function setData($data)
    {
        $this->_model->data = $data;
    }

    /**
     * Sets the handle ("craft" or a plugin handle) that is the subject for the request.
     *
     * @param $handle
     *
     * @return void
     */
    public function setHandle($handle)
    {
        $this->_model->handle = $handle;
    }

    /**
     * @throws EtException|\Exception
     * @return EtModel|null
     */
    public function phoneHome()
    {
        $cacheService = Craft::$app->getCache();

        try {
            $missingLicenseKey = empty($this->_model->licenseKey);

            // No craft/config/license.key file and we can't write to the config folder. Don't even make the call home.
            if ($missingLicenseKey && !$this->_isConfigFolderWritable()) {
                throw new EtException('Craft needs to be able to write to your “craft/config” folder and it can’t.', 10001);
            }

            if (!Craft::$app->getCache()->get('etConnectFailure')) {
                try {
                    $client = new Client([
                        'headers' => [
                            'User-Agent' => $this->_userAgent.' '.\GuzzleHttp\default_user_agent()
                        ],
                        'timeout' => $this->getTimeout(),
                        'connect_timeout' => $this->getConnectTimeout(),
                        'allow_redirects' => $this->getAllowRedirects(),
                    ]);

                    // Potentially long-running request, so close session to prevent session blocking on subsequent requests.
                    Craft::$app->getSession()->close();

                    $response = $client->request('post', $this->_endpoint, [
                        'json' => $this->_model->toArray([
                            // No need to include responseErrors here
                            'licenseKey',
                            'licenseKeyStatus',
                            'licensedEdition',
                            'licensedDomain',
                            'editionTestableDomain',
                            'pluginLicenseKeys',
                            'pluginLicenseKeyStatuses',
                            'data',
                            'requestUrl',
                            'requestIp',
                            'requestTime',
                            'requestPort',
                            'localVersion',
                            'localEdition',
                            'userEmail',
                            'showBeta',
                            'serverInfo',
                            'handle',
                        ])
                    ]);

                    if ($response->getStatusCode() == 200) {
                        // Clear the connection failure cached item if it exists.
                        if ($cacheService->get('etConnectFailure')) {
                            $cacheService->delete('etConnectFailure');
                        }

                        $responseBody = (string)$response->getBody();
                        $etModel = Craft::$app->getEt()->decodeEtModel($responseBody);

                        if ($etModel) {
                            if ($missingLicenseKey && !empty($etModel->licenseKey)) {
                                $this->_setLicenseKey($etModel->licenseKey);
                            }

                            // Cache the Craft/plugin license key statuses, and which edition Craft is licensed for
                            $cacheService->set('licenseKeyStatus', $etModel->licenseKeyStatus);
                            $cacheService->set('licensedEdition', $etModel->licensedEdition);
                            $cacheService->set('editionTestableDomain@'.Craft::$app->getRequest()->getHostName(), $etModel->editionTestableDomain ? 1 : 0);

                            if ($etModel->licenseKeyStatus == LicenseKeyStatus::Mismatched) {
                                $cacheService->set('licensedDomain', $etModel->licensedDomain);
                            }

                            if (is_array($etModel->pluginLicenseKeyStatuses)) {
                                $pluginsService = Craft::$app->getPlugins();

                                foreach ($etModel->pluginLicenseKeyStatuses as $pluginHandle => $licenseKeyStatus) {
                                    $pluginsService->setPluginLicenseKeyStatus($pluginHandle, $licenseKeyStatus);
                                }
                            }

                            return $etModel;
                        }
                    }

                    // If we made it here something, somewhere went wrong.
                    Craft::warning('Error in calling '.$this->_endpoint.' Response: '.$response->getBody(), __METHOD__);

                    if (Craft::$app->getCache()->get('etConnectFailure')) {
                        // There was an error, but at least we connected.
                        $cacheService->delete('etConnectFailure');
                    }
                } catch (RequestException $e) {
                    Craft::warning('Error in calling '.$this->_endpoint.' Reason: '.$e->getMessage(), __METHOD__);

                    if (Craft::$app->getCache()->get('etConnectFailure')) {
                        // There was an error, but at least we connected.
                        $cacheService->delete('etConnectFailure');
                    }
                }
            }
        } // Let's log and rethrow any EtExceptions.
        catch (EtException $e) {
            Craft::error('Error in '.__METHOD__.'. Message: '.$e->getMessage(), __METHOD__);

            if ($cacheService->get('etConnectFailure')) {
                // There was an error, but at least we connected.
                $cacheService->delete('etConnectFailure');
            }

            throw $e;
        } catch (\Exception $e) {
            Craft::error('Error in '.__METHOD__.'. Message: '.$e->getMessage(), __METHOD__);

            // Cache the failure for 5 minutes so we don't try again.
            $cacheService->set('etConnectFailure', true, 300);
        }

        return null;
    }

    // Private Methods
    // =========================================================================

    /**
     * @return null|string
     */
    private function _getLicenseKey()
    {
        $keyFile = Craft::$app->getPath()->getLicenseKeyPath();

        // Check to see if the key exists and it's not a temp one.
        if (!is_file($keyFile)) {
            return null;
        }

        $contents = file_get_contents($keyFile);
        if (empty($contents) || $contents == 'temp') {
            return null;
        }

        return trim(preg_replace('/[\r\n]+/', '', $contents));
    }

    /**
     * @return array
     */
    private function _getPluginLicenseKeys()
    {
        $pluginLicenseKeys = [];
        $pluginsService = Craft::$app->getPlugins();

        foreach ($pluginsService->getAllPlugins() as $plugin) {
            $pluginHandle = $plugin->getHandle();
            $pluginLicenseKeys[$pluginHandle] = $pluginsService->getPluginLicenseKey($pluginHandle);
        }

        return $pluginLicenseKeys;
    }

    /**
     * @param $key
     *
     * @return boolean
     * @throws Exception|EtException
     */
    private function _setLicenseKey($key)
    {
        // Make sure the key file does not exist first, or if it exists it is a temp key file.
        // ET should never overwrite a valid license key.
        if ($this->_getLicenseKey() !== null) {
            throw new Exception('Cannot overwrite an existing valid license.key file.');
        }

        // Make sure we can write to the file
        if (!$this->_isConfigFolderWritable()) {
            throw new EtException('Craft needs to be able to write to your “craft/config” folder and it can’t.', 10001);
        }

        // Format the license key into lines of 50 chars
        preg_match_all("/.{50}/", $key, $matches);
        $formattedKey = '';
        foreach ($matches[0] as $segment) {
            $formattedKey .= $segment.PHP_EOL;
        }

        FileHelper::writeToFile(Craft::$app->getPath()->getLicenseKeyPath(), $formattedKey);

        return true;
    }

    /**
     * @return boolean
     */
    private function _isConfigFolderWritable()
    {
        return FileHelper::isWritable(Craft::$app->getPath()->getConfigPath());
    }
}