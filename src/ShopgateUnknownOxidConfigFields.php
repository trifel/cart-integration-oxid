<?php

declare(strict_types=1);

namespace Shopgate\Framework;

class ShopgateUnknownOxidConfigFields
{
    const OXID_VARTYPE_STRING = 'str';

    private static $oxidConfigUnknownField = 'unknown_fields';

    /** @var array */
    private $unknownOxidConfigFields;

    /** @var \oxConfig */
    private $oxidConfig;

    /** @var ShopgateConfigOxid */
    private $shopgateConfig;

    /** @var marm_shopgate */
    private $marmShopgate;

    /**
     * @param ShopgateConfigOxid $shopgateConfigOxid
     * @param \oxConfig          $oxConfig
     * @param marm_shopgate      $marmShopgate
     */
    public function __construct(ShopgateConfigOxid $shopgateConfigOxid, $oxConfig, $marmShopgate)
    {
        $this->unknownOxidConfigFields = array();
        $this->shopgateConfig          = $shopgateConfigOxid;
        $this->oxidConfig              = $oxConfig;
        $this->marmShopgate            = $marmShopgate;
    }

    /**
     * @param array $unknownOxidConfigFields
     */
    public function save(array $unknownOxidConfigFields)
    {
        $unknownOxidConfigFields = $this->setAlreadyExistingUnknownFields($unknownOxidConfigFields);

        $this->oxidConfig->saveShopConfVar(
            self::OXID_VARTYPE_STRING,
            $this->marmShopgate->getOxidConfigKey(self::$oxidConfigUnknownField),
            $this->shopgateConfig->jsonEncode($unknownOxidConfigFields)
        );
    }

    /**
     * @post $this->shopgateConfig contains all "unknown fields" as object variables
     */
    public function load()
    {
        $unknownOxidConfigFieldsJsonString = $this->oxidConfig->getConfigParam(
            $this->marmShopgate->getOxidConfigKey(self::$oxidConfigUnknownField)
        );

        if (empty($unknownOxidConfigFieldsJsonString)) {
            return;
        }

        $unknownOxidConfigurationFields = $this->shopgateConfig->jsonDecode($unknownOxidConfigFieldsJsonString, true);
        foreach ($unknownOxidConfigurationFields as $field => $value) {
            $setter     = $this->shopgateConfig->camelize($field);
            $methodName = 'set' . $setter;
            if (method_exists($this->shopgateConfig, $methodName)) {
                $this->shopgateConfig->$methodName($value);
            }
        }

        $this->unknownOxidConfigFields = array_keys($unknownOxidConfigurationFields);
    }

    /**
     * @param array $unknownOxidConfigFields
     *
     * @return array
     */
    private function setAlreadyExistingUnknownFields(array $unknownOxidConfigFields)
    {
        foreach ($this->unknownOxidConfigFields as $alreadyExistingOxidConfigUnknownField) {
            if (isset($unknownOxidConfigFields[$alreadyExistingOxidConfigUnknownField])) {
                continue;
            }

            $getter                                                          = $this->shopgateConfig->camelize(
                $alreadyExistingOxidConfigUnknownField
            );
            $unknownOxidConfigFields[$alreadyExistingOxidConfigUnknownField] = $this->shopgateConfig->{'get' . $getter}();
        }

        return $unknownOxidConfigFields;
    }
}
