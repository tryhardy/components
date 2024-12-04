<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Iblock\Iblock;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

class SimpleCatalogSmartFilterComponent extends \CBitrixComponent
{
    /**
     * дополнительные параметры, от которых должен зависеть кэш
     * (filter, user id, user group, section id, etc)
     * @var array
     */
    protected array $cacheAddon = [];

	/**
	 * @var array|string[] модули, которые необходимо подключить для корректной работы компонента
	 */
    protected array $dependModules = ["iblock"];
	protected string $class;

	/**
     * Собираем массив из входящих параметров $arParams
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function onPrepareComponentParams($params): array
    {
		global $APPLICATION;

        if (!$params['CACHE_TIME']) {
            $params['CACHE_TIME'] = 0;
        }

        if (!$params["CACHE_TYPE"]) {
	        $params["CACHE_TYPE"] = "N";
        }

        if (!$params['PREFILTER_NAME']) {
	        $params['PREFILTER_NAME'] = 'preFilter';
        }

	    if (!$params['FILTER_NAME']) {
		    $params['FILTER_NAME'] = 'arrFilter';
	    }

		if (!$params['PROPERTIES_CODE']) {
			$params['PROPERTIES_CODE'] = [];
		}

		if (!$params['FORM_ACTION']) {
			$params['FORM_ACTION'] = $APPLICATION->getCurDir();
		}

		//Основые параметры массива $arParams, необходимые для работы раздела
        $result = [
	        "IBLOCK_ID" => intval($params["IBLOCK_ID"]), //ID иифноблока для фильтрации эл-тов
	        "SECTION_ID" => intval($params["SECTION_ID"]), //ID раздела для фильтрации эл-тов
	        "INCLUDE_SUBSECTIONS" => ($params["INCLUDE_SUBSECTIONS"] == "Y" ? "Y" : "N"), //Выводить элементы подразделов
        ];

        return array_merge($params, $result);
    }

    public function executeComponent()
    {
        try {
			//Проверяем, все ли модули подключены
	        $this->checkRequiredModules();
	        $this->getResult();
	        $this->includeComponentTemplate();
            return $this->arResult;
        }
		catch (Exception $e) {
            ShowError($e->getMessage());
        }
    }

	/**
	 * проверяет подключение необходимых модулей
	 * @throws Main\LoaderException
	 */
	protected function checkRequiredModules()
	{
		foreach ($this->dependModules as $module) {
			if (!Main\Loader::includeModule($module)) {
				throw new Main\LoaderException(
					Loc::getMessage("ITEMS_LIST_MODULE_NOT_FOUND") . " class.php " . $module
				);
			}
		}
	}

	/**
	 * Формируется массив $arResult
	 * @throws Exception
	 */
	protected function getResult()
	{
		$request = $this->request;
		$filterName = $this->arResult['FILTER_NAME'] = $this->arParams['FILTER_NAME'];
		$setFilter = $this->arResult['SET_FILTER'] = strtolower($request->get('set_filter')) == 'y' && $GLOBALS[$filterName];
		$iblockID = $this->arResult['IBLOCK_ID'] = (int) $this->arParams['IBLOCK_ID'];
		$propertyCodes = $this->arParams['PROPERTIES_CODE'] ?: [];
		$this->arResult['FORM_ACTION'] = $this->arParams['FORM_ACTION'];

		if (!$this->class = Iblock::wakeUp($iblockID)->getEntityDataClass()) {
			throw new \Exception("У инфоблока услуг не задан символьный код API");
		}

		$this->arResult['PROPERTIES'] = $this->getProperties(iblockID: $iblockID, propCodes: $propertyCodes);
		$this->arResult['ITEMS'] = $this->getDefaultValues($this->arResult['PROPERTIES']);

		if ($setFilter) {
			$GLOBALS[$filterName] = $filter = $this->getFilter($filterName, $this->arResult['PROPERTIES']);
			if ($this->priceFilter) {
				$GLOBALS[$filterName] += $this->priceFilter;
			}

			$this->arResult['ITEMS'] = $this->fillMinMaxFilterValues($this->arResult['ITEMS']);
			$this->arResult['ITEMS'] = $this->fillActiveFilterValues($this->arResult['ITEMS'], $filter);
			$this->arResult['ITEMS'] = $this->getFilteredValues($filter, $this->arResult['ITEMS']);
		}
	}

	protected function fillMinMaxFilterValues(array $items)
	{
		foreach($items as $key => &$item) {

			if ($item['PROPERTY_TYPE'] !== 'N') continue;
			if (!$this->minmax[$key]) continue;
			if (!$item['VALUES']) continue;

			if ($val = $this->minmax[$key]['MIN']) {
				$item['SHOW'] = true;
				$item['VALUES']['MIN_CURRENT'] = (int) $val;
			}

			if ($val = $this->minmax[$key]['MAX']) {
				$item['SHOW'] = true;
				$item['VALUES']['MAX_CURRENT'] = (int) $val;
			}
		}

		return $items;
	}

	/**
	 * Сделать активными все выбранные значения фильтра
	 * @param array $items
	 * @param array $filter
	 * @return array
	 */
	protected function fillActiveFilterValues(array $items, array $filter = [])
	{
		foreach($items as $key => &$item) {
			$values = [];
			if ($filter["$key.VALUE"] || $filter["$key"]) {
				$values = $filter["$key.VALUE"] ?: $filter["$key"];
			}

			if (empty($values)) continue;

			if (!$item['VALUES']) continue;

			foreach($values as $value) {
				if (!$item['VALUES'][$value]) continue;
				$item['SHOW'] = true;
				$item['VALUES'][$value]['ACTIVE'] = true;
			}
		}

		return $items;
	}


	protected function getFilteredValues(array $filter = [], array $properties = [])
	{
		if (empty($filter)) return $properties;

		$iblockID = (int) $this->arParams['IBLOCK_ID'];
		$sectionID = (int) $this->arParams['SECTION_ID'];
		$preFilter = $GLOBALS[$this->arParams['PREFILTER_NAME']] ?: [];

		$orFilter = [
			'LOGIC' => 'OR',
			$filter
		];

		foreach($filter as $key => $value) {
			$tmpFilter = $filter;
			unset($tmpFilter[$key]);

			if ($tmpFilter) {
				$orFilter[] = $tmpFilter;
			}
		}
		$valuesFilter = $preFilter + [$orFilter];

		if ($this->priceFilter) {
			$valuesFilter = $valuesFilter + $this->priceFilter;
		}

		//$priceFilter
		//Собираем фильтр из дефолтных значений (не отфильтрованных)
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		$cacheName = 'smart-filter-filtered';
		$cacheTime = $this->arParams['CACHE_TIME_DATA'] ?: 0;
		$cacheId = json_encode($valuesFilter) . $iblockID . $sectionID . $cacheName;
		$cacheDir = $cacheName;

		$values = [];
		if ($cache->initCache($cacheTime, $cacheId, $cacheDir)) {
			$values = $cache->getVars();
		}
		else if ($cache->startDataCache()) {
			$cache_manager = Application::getInstance()->getTaggedCache();
			$cache_manager->startTagCache($cacheDir);
			$cache_manager->registerTag("iblock_id_" . $iblockID);

			$class = $this->class;
			$propSelect = ['ID'];
			foreach($properties as $prop) {
				$propSelect['PROPERTY_' . $prop['CODE']] = $prop['CODE'] . '.VALUE';
			}

			$elements = $class::getList([
				'filter' => $valuesFilter,
				'select' => $propSelect,
			])->fetchAll();

			foreach($elements as $element) {
				foreach($element as $key => $value) {

					if (!str_contains($key, 'PROPERTY_')) continue;
					$key = str_replace('PROPERTY_', '', $key);

					if ($this->arResult['ITEMS'][$key]) {
						if (is_numeric($value)) {
							$value = (int) $value;
						}

						$values[$key][$value] = $value;
					}
				}
			}

			$cache_manager->endTagCache();
			$cache->endDataCache($values);
		}

		foreach($properties as $code => &$prop) {

			if (!$values[$code]) {
				foreach($prop['VALUES'] as $key => $value) {
					if (!$values[$code][$key] && is_array($prop['VALUES'][$key])) {
						$properties[$code]['VALUES'][$key]['ENABLED'] = false;
					}
				}
			}
			else {
				foreach($prop['VALUES'] as $key => $value) {
					if (count($filter) <= 1 && $filter[$code . '.VALUE']) continue;
					if (!$values[$code][$key] && is_array($prop['VALUES'][$key])) {
						$properties[$code]['VALUES'][$key]['ENABLED'] = false;
					}
				}
			}
		}

		return $properties;
	}

	/**
	 * Сформировать глобальный фильтр для фильтрации списка элементов
	 * @param string $filterName
	 * @param array $properties
	 * @return array|mixed
	 */
	protected $minmax = [];
	protected $priceFilter = [];
	protected function getFilter(string $filterName, array $properties = [])
	{
		foreach($GLOBALS[$filterName] as $key => $value) {
			if (!$properties[$key]) unset($GLOBALS[$filterName][$key]);
			if (is_array($value) && $value['MIN'] && $value['MAX']) {
				if ($value['MIN']) {
					$this->minmax[$key]['MIN'] = (int) $value['MIN'];
					$this->priceFilter[">=$key.VALUE"] = (int) $value['MIN'];
				}
				if ($value['MIN']) {
					$this->minmax[$key]['MAX'] = (int) $value['MAX'];
					$this->priceFilter["<=$key.VALUE"] = (int) $value['MAX'];
				}
			}
			elseif (is_array($value)) {
				$GLOBALS[$filterName]["$key.VALUE"] = array_values($value);
			}
			else {
				$GLOBALS[$filterName]["$key.VALUE"] = $value;
			}

			unset($GLOBALS[$filterName][$key]);
		}

		return $GLOBALS[$filterName] ?: [];
	}

	protected function getSortedValues()
	{
		$args = func_get_args();
		$data = array_shift($args);
		foreach ($args as $n => $field) {
			if (is_string($field)) {
				$tmp = array();
				foreach ($data as $key => $row) {
					$tmp[$key] = $row[$field];
				}
				$args[$n] = $tmp;
			}
		}
		$args[] = &$data;
		call_user_func_array('array_multisort', $args);
		return array_pop($args);
	}

	protected function getDefaultValues(array $arProperties = [])
	{
		if (empty($arProperties)) return $arProperties;
		$iblockID = (int) $this->arParams['IBLOCK_ID'];
		$sectionID = (int) $this->arParams['SECTION_ID'];
		$preFilter = $GLOBALS[$this->arParams['PREFILTER_NAME']] ?: [];

		//Собираем фильтр из дефолтных значений (не отфильтрованных)
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		$cacheName = 'smart-filter';
		$cacheTime = $this->arParams['CACHE_TIME_DATA'] ?: 0;
		$cacheId = json_encode($preFilter) . $iblockID . $sectionID . $cacheName;
		$cacheDir = $cacheName;

		$props = [];
		if ($cache->initCache($cacheTime, $cacheId, $cacheDir)) {
			$props = $cache->getVars();
		}
		else if ($cache->startDataCache()) {
			$cache_manager = Application::getInstance()->getTaggedCache();
			$cache_manager->startTagCache($cacheDir);
			$cache_manager->registerTag("iblock_id_" . $iblockID);

			$props = $this->getPropertiesValues($iblockID, $arProperties, $sectionID, $preFilter);
			$props = $this->getPropertiesString($props);
			$props = $this->getPropertyEnums($props);
			$props = $this->getElementsByID($props);
			$props = $this->getElementsByXmlID($props);
			$props = $this->getSections($props);
			$props = $this->getHL($props);

			foreach($props as $key => $prop) {
				if (empty($prop['VALUES'])) unset($props[$key]);
			}

			$cache_manager->endTagCache();
			$cache->endDataCache($props);
		}

		return $props;
	}

	/**
	 * Получить информацию о свойствах
	 * @param int $iblockID
	 * @param array $propCodes
	 * @return array
	 */
    protected function getProperties(int $iblockID, array $propCodes = []) : array
    {
	    $sectionID = (int) $this->arParams['SECTION_ID'];
	    $preFilter = $GLOBALS[$this->arParams['PREFILTER_NAME']] ?: [];
	    $cacheTime = $this->arParams['CACHE_TIME'] ?: 0;
	    //Собираем фильтр из дефолтных значений (не отфильтрованных)
	    $cache = \Bitrix\Main\Data\Cache::createInstance();
	    $cacheName = 'smart-filter-props';
	    $cacheId = $iblockID . $sectionID . $cacheName . json_encode($preFilter);
	    $cacheDir = $cacheName;

	    $arProperties = [];
	    if ($cache->initCache($cacheTime, $cacheId, $cacheDir)) {
		    $arProperties = $cache->getVars();
	    }
	    else if ($cache->startDataCache()) {

		    $filter = [
			    'IBLOCK_ID' => $iblockID,
			    'ACTIVE' => 'Y',
			    'SECTION_PROPERTY.SMART_FILTER' => 'Y'
		    ];

		    if (!empty($propCodes)) {
			    $filter['CODE'] = $propCodes;
		    }

		    $props = \Bitrix\Iblock\PropertyTable::getList([
			    'filter' => $filter,
			    'runtime' => [
				    'SECTION_PROPERTY' => [
					    'data_type' => \Bitrix\Iblock\SectionPropertyTable::class,
					    'reference' => [
						    '=this.ID' => 'ref.PROPERTY_ID',
						    '=this.IBLOCK_ID' => 'ref.IBLOCK_ID',
					    ]
				    ]
			    ],
				'order' => ['SORT' => 'ASC', 'ID' => 'DESC'],
			    'cache' => [
				    'ttl' => 3600000
			    ]
		    ])->fetchAll();

		    $arProperties = [];
		    foreach($props as $prop) {
			    $arProperties[$prop['CODE'] ?: $prop['ID']] = $prop;
		    }

		    $cache->endDataCache($arProperties);
	    }

        return $arProperties;
    }

	/**
	 * Получить значения свойств
	 * @param int $iblockID
	 * @param array $props
	 * @param int|null $sectionID
	 * @param array $preFilter
	 * @return array
	 */
    protected function getPropertiesValues(int $iblockID, array $props = [], ?int $sectionID = null, array $preFilter = []) : array
    {
		if (!$props) return $props;
	    $propIDs = array_column($props, 'CODE', 'ID');

		$filter = [
			'IBLOCK_PROPERTY_ID' => array_keys($propIDs),
			'ELEMENT.WF_PARENT_ELEMENT_ID' => null,
			'ELEMENT.ACTIVE' => 'Y',
		];

		if (!empty($preFilter)) {
			$elementTable = Iblock::wakeUp($iblockID)->getEntityDataClass();
			$runtimeName = 'SUBELEMENTS';

			if ($elementTable) {
				$newPrefilter = [
					"$runtimeName.WF_PARENT_ELEMENT_ID" => null,
					"$runtimeName.ACTIVE" => "Y",
				];
				foreach($preFilter as $key => $value) {
					$newPrefilter["$runtimeName.$key"] = $value; //$newPrefilter
				}

				$runtime[$runtimeName] = [
					'data_type' => $elementTable,
					'reference' => [
						'=this.IBLOCK_ELEMENT_ID' => 'ref.ID',
					]
				];
			}

			$filter += $newPrefilter;
		}

		if ($sectionID) {
			$filter['SECTION.IBLOCK_SECTION_ID'] = $sectionID;
			$runtime['SECTION'] = [
				'data_type' => \Bitrix\Iblock\SectionElementTable::class,
				'reference' => [
					'=this.IBLOCK_ELEMENT_ID' => 'ref.IBLOCK_ELEMENT_ID',
				]
			];
		}

		$propElementValues = \Bitrix\Iblock\ElementPropertyTable::getList([
			'filter' => $filter,
			'select' => ['IBLOCK_PROPERTY_ID', 'VALUE', 'VALUE_NUM', 'IBLOCK_ELEMENT_ID'],
			'group' => ['VALUE', 'VALUE_NUM', 'IBLOCK_PROPERTY_ID'],
			'runtime' => $runtime
		])->fetchAll();

        $propertiesResult = [];
        foreach($propElementValues as $propertyValue) {
			if (!$propertyValue['VALUE']) continue;

			$propCode = $propIDs[$propertyValue['IBLOCK_PROPERTY_ID']];

			if (!$propCode) continue;

			$prop = $props[$propCode];

			if (!$prop) continue;

	        $value = $propertyValue['VALUE'];

			if ($prop['USER_TYPE'] == \Bitrix\Iblock\PropertyTable::USER_TYPE_DIRECTORY) {
				$values = explode('/', $propertyValue['VALUE']);
				foreach($values as $v) {
					$values[$v] = $v;
				}

				$value = $values;
			}

	        switch($prop['PROPERTY_TYPE']) {
		        case 'N':
			        $value = (float) $propertyValue['VALUE_NUM'];
			        break;
		        default:
			        if (is_numeric($propertyValue['VALUE'])) $value = (int) $propertyValue['VALUE'];
			        break;
	        }

			if (is_array($value)) {
				foreach($value as $v) {
					$propertiesResult[$propCode][$v] = $v;
				}
			}
			else {
				$propertiesResult[$propCode][$value] = $value;
			}
        }

        foreach($props as $propCode => &$prop) {
            $propValues = $propertiesResult[$propCode] ?: [];

            switch($prop['PROPERTY_TYPE']) {
                case 'N':
	                asort($propValues);
	                $firstValue = array_key_first($propValues);
	                $lastValue = array_key_last($propValues);
	                $propValues = [
		                'MIN' => $firstValue,
		                'MAX' => $lastValue
	                ];
                    break;
                default:
                    break;
            }
	        $prop['VALUES'] = $propValues;
        }

		return $props;
    }

	protected function getPropertyEnums(array $props = [])
	{
		if (!$props) return $props;

		$enumIds = [];
		foreach($props as &$prop) {
			if ($prop['PROPERTY_TYPE'] != 'L') continue;

			$enumIds = array_merge($enumIds, $prop['VALUES']);
		}

		if (!$enumIds) return $props;

		$enums = \Bitrix\Iblock\PropertyEnumerationTable::getList([
			'filter' => [
				'@ID' => $enumIds
			]
		])->fetchAll();
		$enums = array_column($enums, null, 'ID');

		foreach($props as &$prop) {
			if ($prop['PROPERTY_TYPE'] != 'L') continue;

			foreach($prop['VALUES'] as $enumId => $value) {
				if ($enum = $enums[$enumId]) {
					$prop['VALUES'][$enumId] = [
						'ID' => $enumId,
						'NAME' => $enum['VALUE'],
						'ACTIVE' => false,
						'ENABLED' => true,
						'DATA' => $enum,
					];
				}
			}
		}


		return $props;
	}

	protected function getPropertiesString(array $props = [])
	{
		if (!$props) return $props;

		foreach($props as &$prop) {

			if (!$prop['VALUES']) continue;

			if ($prop['USER_TYPE'] || $prop['PROPERTY_TYPE'] != 'S') continue;

			asort($prop['VALUES']);

			foreach($prop['VALUES'] as &$value) {
				$value = [
					'ID' => $value,
					'NAME' => $value,
					'ENABLED' => true,
					'ACTIVE' => false
				];
			}
		}

		return $props;
	}

	/**
	 * Получить список элементов для свойств с типом PROPERTY_TYPE == E
	 * @param array $props
	 * @return array|void
	 */
	protected function getElementsByID(array $props = [])
	{
		if (!$props) return $props;

		$IDS = [];

		foreach($props as $prop) {

			if ($prop['PROPERTY_TYPE'] != 'E') continue;
			if (!$prop['VALUES']) continue;
			if ($prop['USER_TYPE'] == \Bitrix\Iblock\PropertyTable::USER_TYPE_XML_ID) continue;

			$IDS = array_merge($IDS, array_keys($prop['VALUES']));
		}

		$IDS = array_unique($IDS);

		if (!$IDS) return $props;

		$listDB = \Bitrix\Iblock\ElementTable::getList([
			'filter' => [
				'ID' => $IDS,
				'ACTIVE' => 'Y',
				'WF_PARENT_ELEMENT_ID' => null
			],
			'order' => [
				'SORT' => 'ASC',
				'NAME' => 'ASC',
				'ID' => 'DESC'
			],
			'select' => ['ID', 'NAME', 'IBLOCK_ID', 'CODE', 'SORT'],
		])->fetchAll();

		$list = [];
		foreach($listDB as $item) {
			$list[$item['ID']] = $item;
		}

		$values = [];
		foreach($props as $key => $prop) {

			if ($prop['PROPERTY_TYPE'] != 'E') continue;
			if (!$prop['VALUES']) continue;
			if ($prop['USER_TYPE'] == \Bitrix\Iblock\PropertyTable::USER_TYPE_XML_ID) continue;

			foreach($list as $id => $element) {
				if (!$prop['VALUES'][$id]) {
					unset($props[$key]['VALUES'][$id]);
				}
				else {
					$sort = (int) $element['SORT'] ?: 500;
					$values[$key][$id] = [
						'ID' => $id,
						'NAME' => $element['NAME'],
						'ACTIVE' => false,
						'ENABLED' => true,
						'DATA' => $element,
						'SORT' => $sort
					];
					unset($list[$id]);
				}
			}

			$props[$key]['VALUES'] = $values[$key];
		}

		return $props;
	}

	/**
	 * Получить список элементов по XML_ID для свойств с типом PROPERTY_TYPE == E
	 * @param array $props
	 * @return array|void
	 */
	protected function getElementsByXmlID(array $props = [])
	{
		if (!$props) return $props;

		$IDS = [];

		foreach($props as $prop) {

			if ($prop['PROPERTY_TYPE'] != 'E') continue;
			if (!$prop['VALUES']) continue;
			if ($prop['USER_TYPE'] != \Bitrix\Iblock\PropertyTable::USER_TYPE_XML_ID) continue;

			$IDS = array_merge($IDS, array_keys($prop['VALUES']));
		}

		$IDS = array_unique($IDS);

		if (!$IDS) return $props;

		$listDB = \Bitrix\Iblock\ElementTable::getList([
			'filter' => [
				'XML_ID' => $IDS,
				'ACTIVE' => 'Y',
				'WF_PARENT_ELEMENT_ID' => null
			],
			'order' => ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'DESC'],
			'select' => ['XML_ID', 'NAME', 'CODE', 'SORT'],
		])->fetchAll();

		$list = [];
		foreach($listDB as $item) {
			$list[$item['XML_ID']] = $item;
		}

		$values = [];
		foreach($props as $key => $prop) {

			if ($prop['PROPERTY_TYPE'] != 'E') continue;
			if (!$prop['VALUES']) continue;
			if ($prop['USER_TYPE'] != \Bitrix\Iblock\PropertyTable::USER_TYPE_XML_ID) continue;

			foreach($list as $id => $element) {
				if (!$prop['VALUES'][$id]) {
					unset($props[$key]['VALUES'][$id]);
				}
				else {
					$sort = (int) $element['SORT'] ?: 500;
					$values[$key][$id] = [
						'ID' => $id,
						'NAME' => $element['NAME'],
						'ACTIVE' => false,
						'ENABLED' => true,
						'DATA' => $element,
						'SORT' => $sort
					];
					unset($list[$id]);
				}
			}

			$props[$key]['VALUES'] = $values[$key];
		}

		return $props;
	}

	/**
	 * Получить список разделов для свойств с типом PROPERTY_TYPE == E
	 * @param array $props
	 * @return array|void
	 */
	protected function getSections(array $props = [])
	{
		if (!$props) return $props;

		$IDS = [];

		foreach($props as $prop) {
			if ($prop['PROPERTY_TYPE'] != 'G') continue;
			if (!$prop['VALUES']) continue;

			$IDS = array_merge($IDS, array_keys($prop['VALUES']));
		}

		$IDS = array_unique($IDS);

		if (!$IDS) return $props;

		$list = \Bitrix\Iblock\SectionTable::getList([
			'filter' => [
				'ID' => $IDS,
				'ACTIVE' => 'Y',
			],
			'order' => ['SORT' => 'ASC'],
			'select' => ['ID', 'NAME'],
		])->fetchAll();

		$list = array_column($list, 'NAME', 'ID');

		foreach($props as $key => $prop) {

			if ($prop['PROPERTY_TYPE'] != 'S') continue;

			foreach($prop['VALUES'] as $id => $value) {
				if (!$list[$id]) {
					unset($props[$key]['VALUES'][$id]);
				}
				else {
					$props[$key]['VALUES'][$id] = [
						'ID' => $id,
						'NAME' => $list[$id],
						'ACTIVE' => false,
						'ENABLED' => true,
						'DATA' => $list[$id],
					];
				}
			}
		}

		return $props;
	}

	/**
	 * Получить список Элементов из HL блоков
	 * @param array $props
	 * @return array|void
	 */
	protected function getHL(array $props = [])
	{
		if (!$props) return $props;

		$data = [];

		foreach($props as $prop) {

			if ($prop['USER_TYPE'] != \Bitrix\Iblock\PropertyTable::USER_TYPE_DIRECTORY) continue;
			if (!$prop['VALUES']) continue;

			$tableName = $prop['USER_TYPE_SETTINGS_LIST']['TABLE_NAME'];
			if (!$tableName) continue;

			$data[$tableName] = ($data[$tableName] ?: []) + $prop['VALUES'];
		}

		if (!$data) return $props;

		$tableNames = array_keys($data);
		$tableNames = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'filter' => [
				'TABLE_NAME' => $tableNames
			],
			'order' => [
				//'UF_NAME' => 'ASC'
			]
		])->fetchAll();

		foreach($tableNames as $tableInfo) {

			$tmpValues = [];
			$values = $data[$tableInfo['TABLE_NAME']];

			if (!$values) continue;

			$entityDataClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($tableInfo['NAME'])->getDataClass();

			if (!$entityDataClass) continue;

			$HLList = $entityDataClass::getList([
				'filter' => ['UF_XML_ID' => array_keys($values)],
				'order' => ['UF_SORT' => 'ASC', 'UF_NAME' => 'ASC', 'ID' => 'ASC'],
			])->fetchAll();

			if (empty($HLList)) continue;

			foreach($HLList as $hlItem) {

				$tmpValues[$hlItem['UF_XML_ID']] = [
					'XML_ID' => $hlItem['UF_XML_ID'],
					'ID' => $hlItem['ID'],
					'NAME' => $hlItem['UF_NAME'],
					'ACTIVE' => false,
					'ENABLED' => true,
					'DATA' => $hlItem
				];
			}

			$data[$tableInfo['TABLE_NAME']] = $tmpValues;
			unset($values);
			unset($tmpValues);
		}

		if (!$data) return $props;

		foreach($props as $key => $prop) {

			if ($prop['USER_TYPE'] != \Bitrix\Iblock\PropertyTable::USER_TYPE_DIRECTORY) continue;
			if (!$prop['VALUES']) continue;

			$tableName = $prop['USER_TYPE_SETTINGS_LIST']['TABLE_NAME'];
			if (!$tableName) continue;

			$HLData = $data[$tableName];
			if (!$HLData) continue;

			$values = [];
			foreach($HLData as $id => $HLDataValue) {
				if (!$prop['VALUES'][$id]) {
					continue;
				}
				else {
					$values[$id] = $HLDataValue;
					unset($data[$tableName][$id]);
				}
			}

			$props[$key]['VALUES'] = $values;
		}

		return $props;
	}
}
