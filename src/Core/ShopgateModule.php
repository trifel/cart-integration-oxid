<?php

declare(strict_types=1);

namespace Shopgate\Framework\Core;

use ShopgateLogger;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\DbMetaDataHandler;
use OxidEsales\Eshop\Core\Module\Module;
use Shopgate\Framework\ShopgateConfigOxid;
use ShopgateBuilder;
use OxidEsales\Eshop\Core\Exception\LanguageException;
use ShopgateUserHelper;
use ShopgateAddress;
use OxidEsales\Eshop\Application\Model\User as OxidUser;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

class ShopgateModule
{
    /**
     * information about how shopgate config variables
     * are editable in oxid admin GUI
     *
     * @var array
     */
    protected $_aConfig = array(
        //Basic Configuration
        'customer_number'          => array('type' => 'input', 'group' => 'general'),
        'shop_number'              => array('type' => 'input', 'group' => 'general'),
        'apikey'                   => array('type' => 'input', 'group' => 'general'),

        //Mobile website
        'alias'                    => array('type' => 'input', 'group' => 'mobileweb'),
        'cname'                    => array('type' => 'input', 'group' => 'mobileweb'),
        'languages'                => array('type' => 'input', 'group' => 'mobileweb'),
        'redirect_type'            => array(
            'type'    => 'select',
            'group'   => 'mobileweb',
            'options' => array(
                'header'     => 'header',
                'javascript' => 'javascript',
            ),
        ),

        //Export
        'article_identifier'       => array(
            'type'    => 'select',
            'options' => array(
                'oxid'     => 'oxid',
                'oxartnum' => 'oxartnum',
            ),
            'group'   => 'export',
        ),
        'article_name_export_type' => array(
            'type'    => 'select',
            'options' => array(
                'name'      => 'name',
                'shortdesc' => 'shortdesc',
                'both'      => 'both',
            ),
            'group'   => 'export',
        ),
        'language'                 => array(
            'type'      => 'select',
            'options'   => null,
            'group'     => 'export',
            'translate' => false,
            // options will be set later
        ),
        'variant_parent_buyable'   => array(
            'type'    => 'select',
            'group'   => 'export',
            'options' => array(
                'true'  => 'true',
                'false' => 'false',
                'oxid'  => 'oxid',
            ),
        ),

        //Orders
        'unblocked_orders_as_paid' => array('type' => 'checkbox', 'group' => 'orders'),
        'send_mails'               => array('type' => 'checkbox', 'group' => 'orders'),
        'send_mails_to_owner'      => array('type' => 'checkbox', 'group' => 'orders'),
        'orderfolder_unblocked'    => array(
            'type'    => 'select',
            'group'   => 'orders',
            'options' => array(),
            'noerror' => true,
            'prefix'  => false,
        ),
        'orderfolder_blocked'      => array(
            'type'    => 'select',
            'group'   => 'orders',
            'options' => array(),
            'noerror' => true,
            'prefix'  => false,
        ),
        'suppress_order_notes'     => array('type' => 'checkbox', 'group' => 'orders'),

        //DEBUG
        'server'                   => array(
            'type'    => 'select',
            'group'   => 'debug',
            'options' => array(
                'live'   => 'live',
                'pg'     => 'pg',
                'custom' => 'custom',
            ),
        ),
        'api_url'                  => array('type' => 'input', 'group' => 'debug'),
        'plugin'                   => array('type' => false),
        'htaccess_user'            => array('type' => 'input', 'group' => 'debug'),
        'htaccess_password'        => array('type' => 'input', 'group' => 'debug'),

        //hidden fields (no group)
        'shop_is_active'           => array('type' => 'checkbox'),
        'default_memory_limit'     => array('type' => 'input'),
        'default_execution_time'   => array('type' => 'input'),
        'enable_default_redirect'  => array('type' => 'checkbox'),

        'country'                => array('type' => 'input'),
        'currency'               => array('type' => 'input'),
        'mobile_header_parent'   => array('type' => 'input'),
        'mobile_header_prepend'  => array('type' => 'checkbox'),
        'export_buffer_capacity' => array('type' => 'input'),
        'max_attributes'         => array('type' => 'input'),

        'redirectable_get_params' => array('type' => 'input'),
    );

    /**
     * contains array of files which will be included from library
     * to get framework working
     *
     * @var array
     */
    protected $_aFilesToInclude = array(
        'shopgate_plugin.php',
        'shopgate_plugin_ee.php',
        'helpers/config/unknown_oxid_config_fields.php',
        'shopgate_config_oxid.php',
    );

    /**
     * defines where shopgate framework placed.
     */
    const FRAMEWORK_DIR = 'modules/shopgate';

    /**
     * stores created instance of framework object.
     *
     * @var ShopgatePluginOxid
     */
    protected $_oShopgateFramework = null;

    /**
     * @var Shopgate\Framework\Core\ShopgateModule
     */
    private static $instance = null;

    /**
     * @var ShopgateMerchantApi
     */
    private static $shopgateMerchantApi = null;

    /**
     * @var ShopgateConfigOxid
     */
    private $config = null;

    public function __construct()
    {
        $oxConfig = self::getOxConfig();
        if ($oxConfig->getConfigParam($this->getOxidConfigKeyOld('shop_number')) !== null) {
            foreach (array_keys($this->_aConfig) as $key) {
                $oldOxidKey = $this->getOxidConfigKeyOld($key);
                $newOxidKey = $this->getOxidConfigKey($key);
                self::dbExecute("UPDATE oxconfig SET OXVARNAME = '$newOxidKey' WHERE OXVARNAME = '$oldOxidKey'");
                $oxConfig->setConfigParam($newOxidKey, $oxConfig->getConfigParam($oldOxidKey));
            }
        }
    }

    /**
     * returns ShopgateModule object
     *
     * @return Shopgate\Framework\Core\ShopgateModule
     */
    public static function getInstance()
    {
        if (!(self::$instance instanceof Self)) {
            self::$instance = oxNew(Self::class);
            self::$instance->init();
        }

        return self::$instance;
    }

    /**
     * Wrapper to get db-instance with FETCH_MODE_ASSOC
     *
     * we need oxDb::getDb(oxDb::FETCH_MODE_ASSOC)
     *
     * @return oxLegacyDb
     */
    public static function getDb()
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
    }

    /**
     * replace given object to the class instance.
     * USED ONLY FOR PHPUNIT
     *
     * @param marm_shopgate $oNewInstance
     *
     * @return marm_shopgate
     */
    public static function replaceInstance(Self $oNewInstance)
    {
        $oOldInstance   = self::$instance;
        self::$instance = $oNewInstance;

        return $oOldInstance;
    }

    /**
     * returns full path, where framework is placed.
     *
     * @return string
     */
    protected function getFrameworkDir()
    {
        return Self::getOxConfig()->getConfigParam(
            'sShopDir'
        ) . DIRECTORY_SEPARATOR . self::FRAMEWORK_DIR . DIRECTORY_SEPARATOR;
    }

    /**
     * function loads framework by including it
     *
     * @return void
     */
    public function init()
    {
        $this->initConfig();
    }

    /**
     * @return ShopgateMerchantApi
     */
    public function getShopgateMerchantApiInstance()
    {
        if (!self::$shopgateMerchantApi) {
            $this->init();

            $builder                   = new ShopgateBuilder($this->getConfig());
            self::$shopgateMerchantApi = $builder->buildMerchantApi();
        }

        return self::$shopgateMerchantApi;
    }

    /**
     * @return ShopgateConfigOxid
     */
    public function getConfig()
    {
        if ($this->config == null) {
            $this->initConfig();
        }

        return $this->config;
    }

    /**
     * sends config to Shopgate instance only then required params are set:
     * apikey, customer_number and shop_number
     *
     * @return ShopgateConfigOxid
     */
    public function initConfig()
    {
        $this->config = oxNew(ShopgateConfigOxid::class);

        return $this->config;
    }

    /**
     * returns ShopgateFramework object,
     * saves it internally,
     * resets instance if $blReset = true
     *
     * @param bool $blReset
     *
     * @return ShopgatePluginOxid
     */
    public function getFramework($blReset = false)
    {
        if ($this->_oShopgateFramework !== null && !$blReset) {
            return $this->_oShopgateFramework;
        }
        $this->init();

        $shopId = Self::getOxConfig()->getShopId();
        if (!empty($shopId)) {
            /** @var oxShop $oOxShop */
            $oOxShop = oxNew('oxShop');
            $oOxShop->load($shopId);
            if ($oOxShop->oxshops__oxedition->value == 'EE') {
                $this->_oShopgateFramework = oxNew('ShopgatePluginOxidEE');
            }
        }

        if (!$this->_oShopgateFramework) {
            $this->_oShopgateFramework = oxNew('ShopgatePluginOxid');
        }

        /** @var ShopgateBuilder $builder */
        $builder = new ShopgateBuilder($this->config);
        $builder->buildLibraryFor($this->_oShopgateFramework);

        return $this->_oShopgateFramework;
    }

    /**
     * returns array shopgate config name and edit type in oxid (checkbox, input)
     *
     * @return array
     */
    public function _getConfig()
    {
        return $this->_aConfig;
    }

    /**
     * returns shopgate config array with information
     * how to display it in format:
     * array(
     *   [oxid_name] => marm_shopgate_customer_number
     *   [shopgate_name] => customer_number
     *   [type] => checkbox|input|select
     *   [value] => 1234567890
     * )
     *
     * @return array
     */
    public function getConfigForAdminGui()
    {
        $result     = array();
        $oxidConfig = Self::getOxConfig();
        $this->init();

        $shopgateConfig                    = $this->config->toArray();
        $shopgateConfig['customer_number'] = '';
        $shopgateConfig['shop_number']     = '';
        $shopgateConfig['apikey']          = '';
        $shopgateConfig['alias']           = '';
        $shopgateConfig['cname']           = '';

        $this->_aConfig['language']['options'] = Self::getOxLang()->getLanguageNames();

        $folders = Self::getOxConfig()->getConfigParam('aOrderfolder');
        $folders = array_keys($folders);
        $folders = array_combine($folders, $folders);

        $this->_aConfig['orderfolder_unblocked']['options'] = $folders;
        $this->_aConfig['orderfolder_blocked']['options']   = $folders;

        foreach ($this->_getConfig() as $configKey => $options) {
            if ($configKey == 'plugin' || empty($options['group'])) {
                continue;
            }
            $oxidConfigKey = $this->getOxidConfigKey($configKey);
            $value         = $oxidConfig->getConfigParam($oxidConfigKey);
            if ($value === null) {
                $value = $shopgateConfig[$configKey];
            }
            $options['oxid_name']     = $oxidConfigKey;
            $options['shopgate_name'] = $configKey;
            if (!is_null($value)) {
                $options['value'] = $value;
            }
            $result[$options['group']][$configKey] = $options;
        }

        return $result;
    }

    /**
     * will generate key name on which oxid will
     *
     * @param string $sShopgateConfigKey
     *
     * @return string
     */
    public function getOxidConfigKey($sShopgateConfigKey)
    {
        return "sgate_{$sShopgateConfigKey}";
    }

    public function getOxidConfigKeyOld($sShopgateConfigKey)
    {
        $sShopgateConfigKey = strtolower($sShopgateConfigKey);
        $sHash              = md5($sShopgateConfigKey);
        $sStart             = substr($sHash, 0, 3);
        $sEnd               = substr($sHash, -3);

        return "shopgate_{$sStart}{$sEnd}";
    }

    public static function oxinputhelpTag($params)
    {
        $sIdent = isset($params['ident'])
            ? $params['ident']
            : 'IDENT MISSING';
        $iLang  = null;
        $oLang  = Self::getOxLang();

        $iLang = $oLang->getTplLanguage();
        if (!isset($iLang)) {
            $iLang = 0;
        }

        try {
            $sTranslation = $oLang->translateString($sIdent, $iLang, true);
        } catch (LanguageException $oEx) {
            return '';
        }
        if (empty($sTranslation) || $sTranslation == $sIdent) {
            return '';
        }

        return "
			<div class='sgInfoButton' onmouseover='sgShowInfo(\"sgInfoBox-$sIdent\")' onmouseout='sgHideInfo(\"sgInfoBox-$sIdent\")'>i</div>
			<div class='sgInfoBox' id='sgInfoBox-$sIdent'>$sTranslation</div>
		";
    }

    /**
     * replacement for oxUtilsUrl->cleanUrl(), which doesn't exist in Oxid 4.2
     *
     * @param string $url
     *
     * @return string
     */
    public static function cleanUrl($url)
    {
        if (class_exists('oxUtilsUrl') && method_exists('oxUtilsUrl', 'cleanUrl')) {
            return Self::getOxUtilsUrl()->cleanUrl($url);
        }
        $oStr = getStr();
        $url  = $oStr->preg_replace('/(\?|&(amp;)?).+/i', '\1', $url);

        return trim($url, "?");
    }

    public static function getRequestParameter($sName, $blRaw = false)
    {
        /** @noinspection PhpUndefinedMethodInspection */

        return self::getOxConfig()->getRequestParameter($sName, $blRaw);
    }

    /**
     * wrapper for mysql_driver_ADOConnection::GetAll()
     * if result != true, returns empty array
     *
     * @param string $sql
     * @param array  $params
     *
     * @return array
     */
    public static function dbGetAll($sql, $params = array())
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $result = self::getDb()->getAll($sql, $params);

        return !$result
            ? array()
            : $result;
    }


    /**
     * wrapper for mysql_driver_ADOConnection::GetOne()
     * if result != true, returns empty array
     *
     * @param string $sql
     * @param array  $params
     *
     * @return string|null
     */
    public static function dbGetOne($sql, $params = array())
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $result = self::getDb()->GetOne($sql, $params);

        return !$result
            ? null
            : $result;
    }

    /**
     * wrapper for mysql_driver_ADOConnection::Execute()
     *
     * @param string $sql
     *
     * @return object
     */
    public static function dbExecute($sql)
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return self::getDb()->Execute($sql);
    }

    /**
     * @param string $oxCountryId
     *
     * @return string
     */
    public static function getCountryCodeByOxid($oxCountryId)
    {
        /** @var oxCountry $oCountry */
        $oCountry = oxNew('oxcountry');
        $oCountry->load($oxCountryId);

        return $oCountry->oxcountry__oxid->value
            ? $oCountry->oxcountry__oxisoalpha2->value
            : '';
    }

    /**
     * @param string $oxStateId
     *
     * @return string
     */
    public static function getStateCodeByOxid($oxStateId)
    {
        if (version_compare(Self::getOxConfig()->getVersion(), '4.3.0', '<')) {
            return '';
        }
        /** @var oxState $oxState */
        $oxState = oxNew('oxstate');
        $oxState->load($oxStateId);

        return !empty($oxState->oxstates__oxid->value)
            ? $oxState->oxstates__oxid->value
            : '';
    }

    public static function getGenderByOxidSalutation($oxSal)
    {
        return $oxSal == ShopgateUserHelper::OXID_GENDER_FEMALE
            ? ShopgateAddress::FEMALE
            : ShopgateAddress::MALE;
    }

    /**
     * @param oxUser $oxUser
     *
     * @return oxGroups[]
     */
    public static function getUserGroupsByUser(OxidUser $oxUser)
    {
        $groups = array();
        foreach ($oxUser->getUserGroups()->getArray() as $oxGroup) {
            $groups[$oxGroup->oxgroups__oxid->value] = $oxGroup;
        }
        if (isset($groups['oxidpricea'])) {
            unset($groups['oxidpriceb']);
            unset($groups['oxidpricec']);
        }
        if (isset($groups['oxidpriceb'])) {
            unset($groups['oxidpricec']);
        }

        return array_values($groups);
    }

    /**
     * 4.6 has getVar()
     * 4.7+4.8 have both
     * 4.9 has getVariable()
     *
     * @param string $name
     *
     * @return mixed
     */
    public static function getSessionVar($name)
    {
        if (method_exists('oxSession', 'getVariable')) {
            return self::getOxSession()->getVariable($name);
        }

        return null;
    }


    // ### Singleton Wrappers ###

    /** @return oxconfig */
    public static function getOxConfig()
    {
        return Registry::getConfig();
    }

    /** @return shopgate_oxdeliverylist */
    public static function getOxDeliveryList()
    {
        return Registry::get('oxDeliveryList');
    }

    /** @return oxDeliverySetList */
    public static function getOxDeliverySetList()
    {
        return Registry::get('oxDeliverySetList');
    }

    /** @return oxLang */
    public static function getOxLang()
    {
        return Registry::getLang();
    }

    /** @return shopgate_oxsession */
    public static function getOxSession()
    {
        return Registry::get('oxSession');
    }

    /** @return oxUtils */
    public static function getOxUtils()
    {
        return Registry::getUtils();
    }

    /** @return oxUtilsDate */
    public static function getOxUtilsDate()
    {
        return Registry::get('oxUtilsDate');
    }

    /** @return oxUtilsUrl */
    public static function getOxUtilsUrl()
    {
        return Registry::get('oxUtilsUrl');
    }

    /** @return oxUtilsView */
    public static function getOxUtilsView()
    {
        return Registry::get('oxUtilsView');
    }

    // public static function getModuleVersion()
    // {
    //     $moduleSettingService = ContainerFactory::getInstance()
    //         ->getContainer()
    //         ->get(ModuleSettingServiceInterface::class);
    //     return $moduleSettingService->getString('version', 'shopgate');
    // }
}
