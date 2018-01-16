<?php

namespace Urbit\ProductFeed\Model\Feed;

use Magento\Directory\Model\Currency;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface as Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\ConfigurableProduct\Model\Product\Type\ConfigurableFactory as MagentoProductConfigurableFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as ProductRepository;
use Magento\Catalog\Model\Product\Type\AbstractType as ProductType;
use Magento\Catalog\Model\Product\Type\Simple as ProductTypeSimple;
use Urbit\ProductFeed\Model\Config\ConfigFactory;
use Urbit\ProductFeed\Model\Config\Attributes\AttributeManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Calculation\Rate;

use Exception;

/**
 * Class FeedProduct
 * Working and process with Magento Product
 * @package Urbit\ProductFeed\Model\Feed
 *
 * Special properties:
 * @property $isSimple
 *
 * Field properties (for feed $data property):
 * @property string $id
 * @property string $name
 * @property string $description
 * @property string $link
 * @property array $prices
 * @property array $brands
 * @property array $attributes
 * @property string $gtin
 * @property array $categories
 * @property string $image_link
 * @property array $additional_image_links
 * @property string $item_group_id
 * @property array $dimensions
 *
 * @property string $sizeType
 * @property string $size
 * @property string $color
 * @property string $gender
 * @property string $material
 * @property string $pattern
 * @property string $age_group
 * @property string $condition
 */
class FeedProduct
{
    /**
     * Magento product object
     * @var MagentoProduct
     */
    protected $_product;

    /**
     * Factory of configurable products.
     * Used for fetching parent product / item group id
     * @var MagentoProductConfigurableFactory
     */
    protected $_configurableFactory;

    /**
     * Array with product fields
     * @var array
     */
    protected $_data = [];

    /**
     * @var StoreManagerInterface
     */
    protected $_store;

    /**
     * @var CategoryCollection
     */
    protected $_categoryCollectionFactory;

    /**
     * @var Image
     */
    protected $_imageHelper;

    /**
     * @var Currency
     */
    protected $_currency;

    /**
     * @var Config
     */
    protected $_config;

    /**
     * @var ScopeConfig
     */
    protected $_scopeConfig;

    /**
     * @var TaxCalculation
     */
    protected $_taxCalculation;

    /**
     * @var TaxCalculationRate
     */
    protected $_taxCalculationRate;

    /**
     * @var AttributeManager
     */
    protected $_attributeManager;

    /**
     * FeedProduct constructor.
     * @param MagentoProduct $product
     * @param MagentoProductConfigurableFactory $configurableFactory
     * @param ProductRepository $productRepository
     * @param StoreManagerInterface $store
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param Image $imageHelper
     * @param Currency $currency
     * @param ConfigFactory $configFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param Calculation $taxCalculation
     * @param Rate $taxCalculationRate
     * @internal param Calculation $calculation
     */
    public function __construct(
        MagentoProduct $product,
        MagentoProductConfigurableFactory $configurableFactory,
        ProductRepository $productRepository,
        StoreManagerInterface $store,
        CategoryCollectionFactory $categoryCollectionFactory,
        Image $imageHelper,
        Currency $currency,
        ConfigFactory $configFactory,
        ScopeConfigInterface $scopeConfig,
        Calculation $taxCalculation,
        Rate $taxCalculationRate
    ) {
        $this->_product = $productRepository->getById($product->getId());
        $this->_store = $store;
        $this->_currency = $currency;
        $this->_config = $configFactory->create();
        $this->_configurableFactory = $configurableFactory;
        $this->_categoryCollectionFactory = $categoryCollectionFactory;
        $this->_imageHelper = $imageHelper;
        $this->_scopeConfig = $scopeConfig;
        $this->_taxCalculation = $taxCalculation;
        $this->_taxCalculationRate = $taxCalculationRate;
        $this->_attributeManager = AttributeManager::getInstance()->loadProduct($this->_product);

        $this->_product->setStoreId($this->_getStore()->getId());
    }

    /**
     * Get product data for feed
     * @return array
     */
    public function toArray()
    {
        if (empty($this->_data)) {
            $this->process();
        }

        return $this->_data;
    }

    /**
     * Get feed product data fields
     * @param string $name
     * @return mixed|null
     */
    public function __get($name)
    {
        if (isset($this->_data[$name])) {
            return $this->_data[$name];
        }

        if (stripos($name, 'is') === 0 && method_exists($this, $name)) {
            return $this->{$name}();
        }

        $getMethod = "get{$name}";

        if (method_exists($this, $getMethod)) {
            return $this->{$getMethod}();
        }

        return null;
    }

    /**
     * Set feed product data fields
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $setMethod = "set{$name}";

        if (method_exists($this, $setMethod)) {
            $this->{$setMethod}($value);
            return;
        }

        $this->_data[$name] = $value;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return $this|mixed|null
     * @throws Exception
     */
    public function __call($name, $arguments)
    {
        $property      = strtolower(preg_replace("/^unset/", '', $name));
        $propertyExist = isset($this->_data[$property]);

        if ($propertyExist) {
            if (stripos($name, 'unset') === 0) {
                unset($this->_data[$property]);
                return $this;
            }

            if (stripos($name, 'get') === 0) {
                return $this->{$property};
            }

            if (stripos($name, 'set') === 0 && isset($arguments[0])) {
                $this->{$property} = $arguments[0];
                return $this;
            }
        }

        throw new Exception("Unknown method {$name}");
    }

    /**
     * Process Magento Product
     * @return bool
     */
    public function process()
    {
        if (!$this->isSimple()) {
            return false;
        }

        $product = $this->_product;

        $this->processMainFields();
        $this->processPrices();
        $this->processCategories();
        $this->processImages();
        $this->processVariableProduct();
        $this->processAttributes();
        $this->processConfigurableFields();

        return true;
    }

    protected function processMainFields()
    {
        $fields = $this->_config->get("main_fields");

        $id = $this->getAttributeValue($fields['id']);
        if (empty($id)) {
            $id = (string)$this->_product->getId();
        }

        $name = $this->getAttributeValue($fields['name']);
        if (empty($id)) {
            $name = $this->_product->getName();
        }

        $description = $this->getAttributeValue($fields['description']);
        if (empty($id)) {
            $description = $this->_product->getDescription();
        }

        $link = $this->getAttributeValue($fields['link']);
        if (empty($id)) {
            $link = $this->_product->getProductUrl();
        }

        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->link = $link;
    }

    /**
     * Process product prices
     */
    protected function processPrices()
    {
        $prices = [];
        $product = $this->_product;
        $currency = $this->_getCurrency();
        $priceInfo = $product->getPriceInfo();

        //get tax country from config
        $configTaxCountry = $this->_config->get("taxes")['tax_country'];

        //get default shop's country
        $countryCode = $this->_scopeConfig->getValue('general/country/default');
        $productTaxClassId = $product->getTaxClassId();

        $taxCol = $this->_taxCalculation->getResourceCollection();

        $taxCol->addFieldtoFilter('product_tax_class_id', $productTaxClassId);

        $rates = $taxCol->load()->getData();

        $productTax = null;
        $defaultTax = null;

        if ($rates) {
            foreach ($rates as $rate) {
                $rateInfo = $this->_taxCalculationRate->load($rate['tax_calculation_rate_id'])->getData();

                $countryId = $rateInfo['tax_country_id'];

                if ($countryId == $configTaxCountry) {
                    $productTax = $rateInfo['rate'];
                } elseif ($countryId == $countryCode) {
                    $defaultTax = $rateInfo['rate'];
                }
            }
        }

        $regularPrice = $priceInfo->getPrice('regular_price');
        $finalPrice = $priceInfo->getPrice('final_price');

        $regularPriceValue = $regularPrice->getValue();
        $finalPriceValue = $finalPrice->getValue();

        if ($regularPriceValue) {
            $prices[] = [
                "currency" => $currency,
                "value"    => $this->getPriceWithTax($regularPriceValue, $productTax, $defaultTax) * 100,
                "type"     => "regular",
                "vat"      => $this->getFormattedVat($productTax, $defaultTax),
            ];
        }

        if ($finalPriceValue && $finalPriceValue !== $regularPriceValue) {
            $prices[] = [
                "currency" => $currency,
                "value"    => $this->getPriceWithTax($finalPriceValue, $productTax, $defaultTax) * 100,
                "type"     => "sale",
                "vat"      => $this->getFormattedVat($productTax, $defaultTax),
            ];
        }

        $this->prices = $prices;
    }

    protected function getPriceWithTax($priceValue, $tax, $defaultTax)
    {
        return $priceValue + ($tax ? ($priceValue * (float)$tax / 100) : ($defaultTax ? $priceValue * (float)$defaultTax / 100 : 0));
    }

    protected function getFormattedVat($tax, $defaultTax)
    {
        return $tax ? ((float)$tax * 100) : ($defaultTax ? (float)$defaultTax * 100 : null);
    }

    /**
     * Process product categories
     */
    protected function processCategories()
    {
        $categoryIds = $this->_product->getCategoryIds();

        /** @var CategoryCollection $categoryCollection */
        $categoryCollection = $this->_categoryCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('entity_id', $categoryIds);

        $productCategories = [];

        /** @var Category $category */
        foreach ($categoryCollection as $category) {
            $productCategories[] = [
                'id'       => (int)$category->getId(),
                'name'     => $category->getName(),
                'parentId' => (int)$category->getParentId(),
            ];

            $this->processParentCategory($category, $productCategories);
        }

        if (!empty($productCategories)) {
            $this->categories = $productCategories;
        }
    }

    /**
     * get all parent category
     * @param  Category $category Category to process
     * @param  array $categories List of found categories
     */
    public function processParentCategory($category, &$categories)
    {
        $parentId = (int)$category->getParentId();

        if (!$parentId) {
            return;
        }

        foreach ($categories as $cat) {
            if ($cat['id'] == $parentId) {
                return;
            }
        }

        /** @var CategoryCollection $categoryCollection */
        $collection = $this->_categoryCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('entity_id', $parentId);

        /** @var Category $parentCategory */
        $parentCategory = $collection->getFirstItem();

        $newCategory = [
            'id'   => (int)$parentCategory->getId(),
            'name' => $parentCategory->getName(),
        ];

        if ((int)$parentCategory->getParentId()) {
            $newCategory['parentId'] = $parentCategory->getParentId();
            $categories[] = $newCategory;
            $this->processParentCategory($parentCategory, $categories);
        } else {
            $categories[] = $newCategory;
        }
    }

    /**
     * Process product images
     */
    protected function processImages()
    {
        $product = $this->_product;

        if ($product->getImage()) {
            $mainImage = $this->_getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
                . 'catalog/product'
                . $this->_product->getImage();

            $this->image_link = $mainImage;
        }

        if ($images = $this->_product->getMediaGalleryImages()) {
            $additional = [];

            foreach ($images as $image) {
                if ($this->image_link === $image['url']) {
                    continue;
                }

                $additional[] = $image['url'];
            }

            if (!empty($additional)) {
                $this->additional_image_links = $additional;
            }
        }
    }

    /**
     * Process on part of configurable product
     */
    protected function processVariableProduct()
    {
        $parentIDs = $this->_configurableFactory
            ->create()
            ->getParentIdsByChild($this->_product->getId());

        if (!empty($parentIDs)) {
            $this->item_group_id = array_shift($parentIDs);
        }
    }

    /**
     * Process configurable fields (associated custom product attributes)
     */
    protected function processConfigurableFields()
    {
        $fields = $this->_config->get("fields");
        $attributes = $this->_config->get("attributes");
        $units = $this->_config->get("units");
        $inventory = $this->_config->get('inventory');

        $brand = $this->getAttributeValue($attributes['brands']);

        if ($brand && $brand !== 'No') {
            $this->brands = [
                [
                    'name' => $brand,
                ],
            ];
        }

        foreach (['height', 'length', 'width'] as $key) {
            $keyField = "dimention_{$key}";

            if (!isset($fields[$keyField]) || !$fields[$keyField]) {
                continue;
            }

            $attr = $this->getAttributeValue($fields[$keyField]);

            if (!$attr || $attr <= 0) {
                continue;
            }

            if (isset($units['dimension'])) {
                $dimensions[$key] = [
                    'value' => (float)$attr,
                    'unit'  => $units['dimension'],
                ];
            } else {
                $dimensions[$key] = [
                    'value' => (float)$attr,
                ];
            }
        }

        $weight = (float)$this->_product->getWeight();

        if ($weight > 0) {
            if (isset($units['weight'])) {
                $dimensions['weight'] = [
                    'value' => $weight,
                    'unit'  => $units['weight'],
                ];
            } else {
                $dimensions['weight'] = [
                    'value' => $weight,
                ];
            }
        }

        if (!empty($dimensions)) {
            $this->dimensions = $dimensions;
        }

        foreach (["ean", "mpn"] as $key) {
            if (!isset($inventory[$key]) || !$inventory[$key]) {
                continue;
            }

            $attr = $this->getAttributeValue($inventory[$key]);

            if ($attr === null) {
                continue;
            }

            $this->{$key} = $attr;
        }

        foreach (["sizeType", "size", "color", "gender", "material", "pattern", "age_group", "condition"] as $key) {
            if (!isset($attributes[$key]) || !$attributes[$key]) {
                continue;
            }

            $attr = $this->getAttributeValue($attributes[$key]);

            if ($attr === null) {
                continue;
            }

            $this->{$key} = $attr;
        }
    }

    /**
     * Process product attributes
     */
    protected function processAttributes()
    {
        $attributes = $this->_config->get("attributes");

        if ($attributes['additional'] == null) {
            return;
        }

        $additionalAttributes = [];

        $productAttributes = $this->_product->getAttributes();

        foreach ($productAttributes as $k => $attr) {
            $code = $attr->getAttributeCode();
            if ($code == "quantity_and_stock_status") {
                continue;
            }

            $type = $attr->getFrontend()->getInputType();
            $value = $this->getAttributeValue($code);
            $id = $attr->getId();

            if (strpos($attributes['additional'], $code) === false || $value === null) {
                continue;
            }

            switch ($type) {
                case 'select':
                    $options = $attr->getSource()->getAllOptions();

                    foreach ($options as $option) {
                        if ($option['value'] == $id) {
                            $value = $option['label'];
                        }
                    }
                    $type = 'string';
                    break;

                case 'price':
                case 'decimal':
                    $type = 'float';
                case 'float':
                    $value = (float)$value;
                    break;

                case 'multiselect':
                    $type = 'string';
                    break;

                case 'textarea':
                case 'text':
                case 'varchar':
                    $type = 'string';
                case 'url':
                case 'string':
                    if (!$value) {
                        continue 2;
                    }

                    if (preg_match("#^[a-zA-Z]://#", $value)) {
                        $type = 'url';
                    }

                    $value = (string)$value;
                    break;

                case 'datetime':
                case 'date':
                    $type = 'time';

                    if ($value != "") {
                        $value = date('c', strtotime($value));
                    } else {
                        continue 2;
                    }

                    break;

                case 'boolean':
                case 'bool':
                    if (in_array(strtolower($value), ['yes', 'no'])) {
                        $value = strtolower($value) === 'yes';
                    }

                    $type = 'boolean';
                    break;

                case 'int':
                    $type = 'number';
                case 'number':
                    $value = (int)$value;
                    break;

                case 'static':
                default:
                    continue 2;
            }

            $additionalAttributes[] = [
                'name'  => $code,
                'type'  => $type,
                //'unit'  => null,
                'value' => $value,
            ];
        }

        if (!empty($additionalAttributes)) {
            $this->attributes = $additionalAttributes;
        }
    }

    /**
     * @param $type
     * @return string
     */
    protected function checkType($type)
    {
        switch ($type) {
            // String
            case 'varchar':
            case 'url':
            case 'text':
            case 'string':
                $type = 'string';
                break;
            // Integer
            case 'int':
                $type = 'number';
                break;
            // Float
            case 'price':
            case 'decimal':
                $type = 'float';
                break;
            // Boolean
            case 'bool':
                $type = 'boolean';
                break;
            // Date/Time
            case 'datetime':
                $type = 'time';
        }

        return $type;
    }

    /**
     * Check if product have simple type
     * @return bool
     */
    public function isSimple()
    {
        /** @var ProductType $type */
        $type = $this->_product->getTypeInstance();

        return $type instanceof ProductTypeSimple;
    }

    /**
     * @return Store
     */
    protected function _getStore()
    {
        return $this->_store->getStore();
    }

    /**
     * Helper function
     * Get store currency
     * @return string
     */
    protected function _getCurrency()
    {
        return $this->_getStore()->getCurrentCurrencyCode();
    }

    /**
     * Helper function
     * Get product attribute object
     * @param string $name
     * @return
     */
    protected function getAttribute($name)
    {
        return $this->_product->getResource()->getAttribute($name);
    }

    /**
     * Helper function
     * Get product attribute value
     * @param string $name
     * @return mixed
     */
    protected function getAttributeValue($name)
    {
        if ($this->_attributeManager->checkAttributeCode($name)) {
            return $this->_attributeManager->getAttributeValue($name);
        }

        $attr = $this->getAttribute($name);

        if (!$attr) {
            return null;
        }

        return $attr->getFrontend()->getValue($this->_product);
    }

    /**
     * Helper function
     * Get product attribute type
     * @param $name
     * @return mixed|null|string
     */
    protected function getAttributeType($name)
    {
        $attr = $this->getAttribute($name);

        if (!$attr) {
            return null;
        }

        return $attr->getBackendType();
    }
}

