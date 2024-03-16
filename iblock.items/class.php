<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Iblock\Component\Tools;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Iblock;
use Bitrix\Main\ORM\Query\Result;
use Bitrix\Main\UI\PageNavigation;
use CBitrixComponent;
use CDBResult;
use CIBlock;
use CPageOption;
use Tryhardy\BitrixFilter\ElementsFilter;

class SampleListIblockItemsComponent extends \CBitrixComponent
{
	protected const USER_TYPE_USER = 'UserID';

	protected int $iblockId;
    //кешируемые ключи arResult
    protected array $resultCacheKeys = [
	    "ID",
	    "IBLOCK_ID",
	    "TITLE",
	    "DESCRIPTION",
	    "SECTION_ID",
	    "SECTION",
	    "NAV_CHAIN",
	    "ITEMS",
	    "NAV_DATA"
    ];

	protected array $fileIdsArray = [];

	//массив параметров для select по умолчанию
    private array $defaultSelect = [
		"ID",
		"IBLOCK_ID",
		"NAME",
	    "CODE",
	    "IBLOCK_SECTION_ID",
	    "PREVIEW_TEXT",
	    "PREVIEW_PICTURE",
    ];

    /**
     * дополнительные параметры, от которых должен зависеть кэш
     * (filter query, user id, section id, etc)
     * @var array
     */
    protected array $cacheAddon = [];

    //модули, которые необходимо подключить для корректной работы компонента
    protected array $dependModules = ["iblock"];

    //параметры, которые необходимо проверить
    protected array $requiredParams = [
        "int" => [
            "IBLOCK_ID",
        ],
        "isset" => [],
    ];

    /**
     * парамтеры постраничной навигации
     * @var array
     */
	protected int $maxLimit = 150;
	protected int $limit = 20;
	protected string $navigationName = "pagination";        //название объекта пагинации
	protected bool $showNav = false;                        // выводить ли массив с пагинацией

	/**
	 * @var PageNavigation|null
	 */
	protected $navParams = null;    //Объект пагинации

	/**
	 * @var null ElementsFilter
	 */
    protected ?ElementsFilter $filter = null;                           //внешний фильтр
    protected array $arSort = [];                           //внешняя сортировка
	protected bool $useRandomSort = false;                  //Если в SORT передается параметр RAND

    protected bool $fromCache = true;                       //флаг использования кэша

	protected string $defaultDateFormat = 'd.m.Y';          //формат даты по умолчанию

    /**
     * Подключаем языковые файлы
     */
    public function onIncludeComponentLang()
    {
        $this->includeComponentLang(basename(__FILE__));
        Loc::loadMessages(__FILE__);
    }

    /**
     * подготавливает входные параметры
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function onPrepareComponentParams($params): array
    {
		global $USER;

		//под админом в режиме правки?
        $bDesignMode = $GLOBALS["APPLICATION"]->GetShowIncludeAreas() && is_object($USER) && ($USER->IsAdmin());

	    //Отключаем кэш в режиме правки
	    if ($bDesignMode) {
			$params["CACHE_TIME"] = 0;
		}

		//Устанавливаем количество элементов на странице (минимум 1, максимум 150)
        $params["COUNT_LIMIT"] = (int) $params["COUNT_LIMIT"];
        if ($params["COUNT_LIMIT"] <= 0 || $params["COUNT_LIMIT"] > $this->maxLimit) {
            $params["COUNT_LIMIT"] = $this->limit;
        }

		//Пробрасываем исключение, если в параметры компонента передается неправильный объект фильтра
        if (isset($params["FILTER"]) && !empty($params["FILTER"]) && !($params["FILTER"] instanceof ElementsFilter)) {
	        throw new Exception("FILTER object not found");
        }

		//Если фильтр не передан, заводим просто пустой экземпляр класса ElementsFilter
		if (!$params["FILTER"]) {
			$params["FILTER"] = ElementsFilter::getInstance();
		}

		//Если в параметрах компонента переданы дополнительные поля для выборки
        if (isset($params["ADDITIONAL_SELECT"]) && !empty($params["ADDITIONAL_SELECT"])) {
            foreach ($params["ADDITIONAL_SELECT"] as $selectItem) {
	            $params["ADDITIONAL_SELECT"][] = trim($selectItem);
            }
        }

		//Основые параметры массива $arParams, необходимые для работы раздела
        $result = [
			"TITLE" => $params["TITLE"],
	        "DESCRIPTION" => $params["DESCRIPTION"],

	        "DESIGN_MODE" => $bDesignMode, //Включен режим правки
	        "IBLOCK_ID" => intval($params["IBLOCK_ID"]), //ID иифноблока для фильтрации эл-тов
	        "SECTION_ID" => intval($params["SECTION_ID"]), //ID раздела для фильтрации эл-тов
	        "INCLUDE_SUBSECTIONS" => ($params["INCLUDE_SUBSECTIONS"] == "Y" ? "Y" : "N"), //Выводить элементы подразделов
	        "SECTION_GLOBAL_ACTIVE" => ($params["SECTION_GLOBAL_ACTIVE"] == "Y" ? "Y" : "N"), //Учитывать глобальную активность разделов
	        "COUNT_LIMIT" => $params["COUNT_LIMIT"], //Количество элементов на странице
	        "SORT" => $params["SORT"] && is_array($params['SORT']) ? $params["SORT"] : ['ID' => 'DESC'], //Сортировка
	        "SHOW_PROPERTIES" => $params["SHOW_PROPERTIES"] ? $params["SHOW_PROPERTIES"] : [], //Выводить свойства в списке
	        "SHOW_ACTIVE" => ($params["SHOW_ACTIVE"] == "Y" ? "Y" : "N"), //Выводить только активные
	        "SET_DETAIL_URL" => ($params["SET_DETAIL_URL"] == "N" ? "N" : "Y"), //Формировать URL-адрес

	        "DATE_FORMAT" => $params["DATE_FORMAT"] ?: $this->defaultDateFormat, //Формат даты для вывода,
	        "FORMAT_DATE" => ($params["FORMAT_DATE"] == "Y" ? "Y" : "N"), //Форматировать дату

	        "DETAIL_URL" => trim($params["DETAIL_URL"]), //Шаблон детального URL
	        "SECTION_URL" => trim($params["SECTION_URL"]), //Шаблон URL раздела
	        "LIST_URL" => trim($params["LIST_URL"]),  //Шаблон URL списка

	        "SET_META" => ($params["SET_META"] == "Y" ? "Y" : "N"), //Устанавливать мета-теги

	        "SET_CHAIN" => ($params["SET_CHAIN"] == "Y" ? "Y" : "N"), //Устанавливать цепочку навигации

	        "CACHE_GROUPS" => ($params["CACHE_GROUPS"] == "Y" ? "Y" : "N"), //Учитывать права доступа
	        "CACHE_TIME" => $params["CACHE_TIME"], //Время кэширования
	        "CACHE_TYPE" => $params["CACHE_TYPE"], //Тип кэширования
	        "CACHE_FILTER" => ($params["CACHE_FILTER"] == "Y" ? "Y" : "N"), //Учитывать фильтр

	        "RETURN_ITEMS" => ($params["RETURN_ITEMS"] == "Y" ? "Y" : "N"), // В качестве результата выполнения компонента возвращать $arResult

	        "FILE_404" => trim($params["FILE_404"]), //Файл с сообщением, если не найдены элементы
	        "SHOW_404" => ($params["SHOW_404"] == "Y" ? "Y" : "N"), //Выводить сообщение, если не найдены элементы
	        "SET_STATUS_404" => ($params["SET_STATUS_404"] == "Y" ? "Y" : "N"), //Устанавливать статус 404, если не найдены элементы

	        //Блок пагинации
	        "SHOW_NAV" => ($params["SHOW_NAV"] == "N" ? "N" : "Y"), //Выводить пагинацию
	        "PAGER_SHOW_ALWAYS" => ($params["PAGER_SHOW_ALWAYS"] == "Y" ? "Y" : "N"), //Выводить всегда
	        "PAGER_TITLE" => trim($params["PAGER_TITLE"]), //Заголовок блока пагинации
	        "PAGER_TEMPLATE" => trim($params["PAGER_TEMPLATE"]), //Шаблон пагинации
	        "NAVIGATION_NAME" => $params["NAVIGATION_NAME"] ?: ($this->getTemplateName() ?: 'nav') //Наименование объекта пагинации
        ];

        return array_merge($params, $result);
    }

	/**
	 * подготавливает классовые свойства
	 * @param array $params
	 * @return void
	 */
	protected function onPrepareClassProperties(array $params = []) : void
	{
		$this->iblockId = $params["IBLOCK_ID"];
		$this->fromCache = $params["CACHE_TIME"] > 0;
		$this->filter = $this->onPrepareFilter();

		$this->arSort = $params["SORT"];
		if (in_array("RAND", array_keys($this->arSort))) $this->useRandomSort = true;

		$this->showNav = $this->arParams["SHOW_NAV"] === "Y";
		$this->limit = $this->arParams["COUNT_LIMIT"];
		$this->navigationName = $this->arParams["NAVIGATION_NAME"];

	}

	/**
	 * Prepares the filter object for selecting elements
	 * @return ElementsFilter
	 * @throws Exception
	 */
	protected function onPrepareFilter() : ElementsFilter
	{
		$params = $this->arParams;
		$filter = ElementsFilter::getInstance();

		$filter->add("IBLOCK_ID", $params["IBLOCK_ID"]);

		if ($params["SECTION_ID"] > 0) {
			$includeSubsections = $params["INCLUDE_SUBSECTIONS"] !== "N";
			$filter->addSections("IBLOCK_SECTION_ID", (int) $params["SECTION_ID"], $includeSubsections);
		}

		if ($params["SECTION_GLOBAL_ACTIVE"] === "Y") {
			$filter->add("IBLOCK_SECTION.GLOBAL_ACTIVE", $params["SECTION_GLOBAL_ACTIVE"]);
		}

		if ($this->arParams["SHOW_ACTIVE"] === "Y") {
			$filter->add("ACTIVE", "Y");
		}

		return $filter;
	}

    public function executeComponent()
    {
        try {
			//Проверяем, все ли обязательные параметры заполнены
	        $this->checkParams($this->arParams);
			//Проверяем, все ли модули подключены
	        $this->checkModules();
			//Устанавливаем свойства класса, необходимые для работы методов ниже
			$this->onPrepareClassProperties($this->arParams);

            if (!$this->readDataFromCache()) {
				$this->getResult();
	            echo "<pre>";
	            print_r('============$this->arResult==================');
	            echo "</pre>";
	            echo "<pre>";
	            print_r($this->arResult);
	            echo "</pre>";
	            $this->arResult['TEMPLATE_DATA'] = $this->changeKeyCase($this->arResult);

	            if (defined("BX_COMP_MANAGED_CACHE")) {
                    global $CACHE_MANAGER;
                    $CACHE_MANAGER->RegisterTag("iblock_id_" . $this->iblockId);
                }

                if (empty($this->arResult["ITEMS"]) && $this->arParams["SET_STATUS_404"] === "Y") {
                    $this->abortResultCache();
                    Tools::process404(
                        "",
                        ($this->arParams["SET_STATUS_404"] === "Y"),
                        ($this->arParams["SET_STATUS_404"] === "Y"),
                        ($this->arParams["SHOW_404"] === "Y"),
                        $this->arParams["FILE_404"]
                    );
                }
				else {
                    $this->initEditButtons();
					$this->putDataToCache();
                    $this->includeComponentTemplate();
                    $this->endDataCache();
                }
            }

            $this->executeEpilog();
	        $this->showEditButtons();

            return $this->arResult;
        }
		catch (Exception $e) {
            $this->abortDataCache();
            ShowError($e->getMessage());
        }
    }

	/**
	 * A method to recursively change the case of keys in a multidimensional array.
	 *
	 * @param array $array The input array to change the key cases.
	 * @return array The array with keys' case changed.
	 */
	protected function changeKeyCase(array $array) : array
	{
		$case = CASE_LOWER;
		$array = array_change_key_case($array, $case);

		foreach($array as &$value) {
			if (is_array($value)) {
				$value = $this->changeKeyCase($value);
			}
		}
		return $array;
	}

	/**
	 * действия после выполения компонента, например установка заголовков из кеша
	 */
	protected function executeEpilog()
	{
		global $APPLICATION;
		$params = $this->arParams;
		$result = $this->arResult;
		$result['META'] = $this->setMeta();

		if ($params["SET_CHAIN"] == "Y" && !empty($result["NAV_CHAIN"])) {
			array_walk($result["NAV_CHAIN"], function ($section) use ($APPLICATION) {
				$APPLICATION->AddChainItem(
					$result['META']["SECTION_PAGE_TITLE"] ?? $section["~NAME"],
					$section["SECTION_PAGE_URL"] ?? ""
				);
			});
		}
	}

	/**
	 * проверяет подключение необходимых модулей
	 * @throws Main\LoaderException
	 */
	protected function checkModules()
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
     * проверяет заполнение обязательных параметров
     * @throws Main\ArgumentNullException
     */
    protected function checkParams(array $params = [])
    {
	    foreach ($this->requiredParams as $key => $requiredRow) {
		    $this->requiredParams[$key] = array_merge(
			    (array) $this->requiredParams[$key],
			    (array) $params["REQUIRED_" . strtoupper($key) . "_PARAMS"]
		    );
	    }
	    foreach ($this->requiredParams["int"] as $param) {
            if (intval($this->arParams[$param]) <= 0) {
                throw new Main\ArgumentNullException($param);
            }
        }
        foreach ($this->requiredParams["isset"] as $param) {
            if (!isset($this->arParams[$param]) && !empty($this->arParams[$param])) {
                throw new Main\ArgumentNullException($param);
            }
        }
    }


    /**
     * определяет читать данные из кэша или нет
     * @return bool
     */
    protected function readDataFromCache() : bool
    {
		$user = $GLOBALS["USER"];
		$params = $this->arParams;

		if (!$this->fromCache) {
			return false;
		}

        if ($params["CACHE_FILTER"] == "Y") {
            $this->cacheAddon[] = $this->filter->GetFilter();
        }

        if ($params["CACHE_GROUPS"] == "Y" && is_object($user)) {
            $this->cacheAddon[] = $user->GetUserGroupArray();
        }

	    if (!empty($this->arSort)) {
		    $this->cacheAddon[] = $this->arSort;
	    }

        if ($this->navParams) {
            $this->cacheAddon[] = $this->navParams;
        }

        return !($this->StartResultCache(false, $this->cacheAddon));
    }

    /**
     * завершает сохранение кэшируемых данных
     *
     * @return bool
     */
    protected function endDataCache() : bool
    {
        if ($this->fromCache) {
            return false;
        }

        $this->EndResultCache();

        return true;
    }

	/**
	 * @param int $iblock
	 * @param int $id
	 * @param CBitrixComponent $component
	 * @param bool $isSect раздел или элемент
	 *
	 * @noinspection PhpUnused
	 */
	public function getEditButtons(int $iblock, int $id, CBitrixComponent &$component, bool $isSect = false)
	{
		$user = $GLOBALS["USER"];

		if ($iblock <= 0) return;

		if (!$user->IsAuthorized()) return;

		if ($isSect) {
			$arButtons = CIBlock::GetPanelButtons($iblock, 0, $id, ["SESSID" => false, "CATALOG" => true]);

			$edit = $arButtons["edit"]["edit_section"]["ACTION_URL"];
			$delete = $arButtons["edit"]["delete_section"]["ACTION_URL"];

			$component->AddEditAction($id, $edit, CIBlock::GetArrayByID($iblock, "SECTION_EDIT"));
			$component->AddDeleteAction($id, $delete, CIBlock::GetArrayByID($iblock, "SECTION_DELETE"),
				["CONFIRM" => GetMessage("CT_BCSL_ELEMENT_DELETE_CONFIRM")]);
		}
		else {
			$arButtons = CIBlock::GetPanelButtons($iblock, $id, 0, array("SECTION_BUTTONS" => false, "SESSID" => false));

			$edit = $arButtons["edit"]["edit_element"]["ACTION_URL"];
			$delete = $arButtons["edit"]["delete_element"]["ACTION_URL"];

			$component->AddEditAction($id, $edit, CIBlock::GetArrayByID($iblock, "ELEMENT_EDIT"));
			$component->AddDeleteAction($id, $delete, CIBlock::GetArrayByID($iblock, "ELEMENT_DELETE"),
				array("CONFIRM" => GetMessage("CT_BNL_ELEMENT_DELETE_CONFIRM")));
		}
	}

	/**
	 * Формируется массив $arResult
	 * @throws Exception
	 */
	protected function getResult()
	{
		$showNavCHain = $this->arParams["SET_CHAIN"] === "Y";
		$propertyCodes = $this->arParams["ADDITIONAL_PROPERTIES"] ?: [];
		$getProperties = $this->arParams["SHOW_PROPERTIES"] !== 'N';
		$hidePicture = $this->arParams["HIDE_PICTURES"] === "Y";

		$this->arResult = [
			"TITLE" => $this->arParams["TITLE"] ?: null,
			"DESCRIPTION" => $this->arParams["DESCRIPTION"] ?: null,
			"ITEMS" => [],
		];

		$this->arResult['SECTION_ID'] = $this->arParams['SECTION_ID'] ?? null;

		[$arItems, $navParams] = $this->getItems($this->filter, $this->limit, $this->showNav);

		if ($getProperties) {
			//TODO сделать блок кода ниже рекурсивным
			$getRecursice = true;
			$getSecondLevelItems = true;

			//Получить свойства элементов и их значения с подзапросом для enums (1 уровень)
			$arItems = $this->getProperties($this->iblockId, $arItems, $propertyCodes);
			//Получить значения HL блоков, если у эл-тов есть свойства типа "Справочник"
			$arItems = $this->getHLBlockPropertiesElements($arItems);
			//Получить разделы для свойств типа "Привязка к разделам"
			$arItems = $this->getSectionPropertiesElements($arItems);
			//Получить пользователя для свойств типа "Привязка к пользователям"
			$arItems = $this->getUserPropertiesElements($arItems);

			//Если у элеметов из массива $arItems есть свойства типа "Привязка к элементам", то получить эти элементы и их свойства
			$arPropertiesElements = $this->getIblockPropertiesElements($arItems);
			$arPropertiesElements = $this->getHLBlockPropertiesElements($arPropertiesElements);
			$arPropertiesElements = $this->getSectionPropertiesElements($arPropertiesElements);
			$arPropertiesElements = $this->getUserPropertiesElements($arPropertiesElements);

			if ($getSecondLevelItems) {
				$arSubPropertiesElements = $this->getIblockPropertiesElements($arPropertiesElements);
				$arSubPropertiesElements = $this->getHLBlockPropertiesElements($arSubPropertiesElements);
				$arSubPropertiesElements = $this->getSectionPropertiesElements($arSubPropertiesElements);
				$arSubPropertiesElements = $this->getUserPropertiesElements($arSubPropertiesElements);

				$arPropertiesElements = $this->associateItemsWithArray($arPropertiesElements, $arSubPropertiesElements);
			}

			$arItems = $this->associateItemsWithArray($arItems, $arPropertiesElements);
		}

		if (!$hidePicture) {
			$fileArray = $this->getFilesArrayFromItems();

			if (!empty($fileArray)) {
				$arItems = $this->checkArrayAndFillFile($arItems, $fileArray);
			}
		}

		$this->arResult["NAV_DATA"] = $this->setNavData($navParams);
		$this->arResult["ITEMS"] = $arItems;

		if ($showNavCHain) {
			$this->arResult["NAV_CHAIN"] = $this->getNavCHain();
		}

		$this->getSectionData();
	}

	protected function checkArrayAndFillFile(array $array, array $fileArray)
	{
		foreach($array as $code => &$internal) {

			if (is_array($internal) && $code !== 'PROPERTIES') {
				$internal = $this->checkArrayAndFillFile($internal, $fileArray);
			}
			elseif (is_array($internal) && $code === 'PROPERTIES') {
				foreach($internal as $propCode => &$property) {
					if ($property['LINK_IBLOCK_ID'] && is_array($property['VALUE'])) {
						$property['VALUE'] = $this->checkArrayAndFillFile($property['VALUE'], $fileArray);
					}
					elseif ($property['PROPERTY_TYPE'] == 'F') {
						if (is_array($property['VALUE'])) {
							foreach($property['VALUE'] as &$currentFileId) {
								if ($currentFileId) $currentFileId = $fileArray[$currentFileId];
							}
						}
						elseif ($property['VALUE']) {
							$property['VALUE'] = $fileArray[$property['VALUE']];
						}
					}
				}
			}
			else {
				if ($code === 'PREVIEW_PICTURE' && $internal > 0) {
					$internal = $fileArray[$internal] ?: $internal;
				}
			}
		}

		return $array;
	}

	/**
	 * Associates items with an array of property elements.
	 *
	 * @param array $arItems The array of items to be associated.
	 * @param array $arPropsElements The array of property elements to associate with the items.
	 * @return array The array of items with associated property elements.
	 */
	protected function associateItemsWithArray(array $arItems, array $arPropsElements) : array
	{
		foreach($arItems as &$arItem) {
			$properties = &$arItem['PROPERTIES'];
			foreach($properties as &$property) {
				if ($property['PROPERTY_TYPE'] !== 'E' || empty($property['VALUE'])) {
					continue;
				}

				$value = $property['VALUE'];
				if (is_array($value)) {
					foreach($value as $i => $v) {
						$key = array_search($v, array_column($arPropsElements, 'ID'));
						if ($key !== false) {
							$value[$i] = $arPropsElements[$key];
						}
					}
				} else {
					$key = array_search($value, array_column($arPropsElements, 'ID'));
					if ($key !== false) {
						$value = $arPropsElements[$key];
					}
				}

				$property['VALUE'] = $value;
			}
		}
		return $arItems;
	}

	/**
	 * Формируется список элементов без дополнительных свойств
	 * @param ElementsFilter $filter
	 * @param int $limit
	 * @param bool $showNav
	 * @param bool $debug
	 * @return array
	 */
	protected function getItems(ElementsFilter $filter, int $limit, bool $showNav, $debug = false) : array
	{
		$selectItemsFields = $this->getSelect();
		$arSort = $this->arSort ?: [];

		//Если для сортировки используется поле RAND
		if ($this->useRandomSort) {
			$filter->setRandomSort();
		}

		$arFilter = $filter->getFilter();
		$arRuntime = $filter->getRuntime();

		//Навигация
		$navParams = null;
		if ($showNav) {
			//Инициализируем объект навигации, если это требуется
			$navParams = $this->initNavData();
		}

		$dbItems = ElementTable::getList([
			"filter" => $arFilter,
			"select" => $selectItemsFields,
			"offset" => $navParams ? $navParams->getOffset() : 0,
			"limit" => $navParams ? $navParams->getLimit() : $limit,
			"count_total" => $showNav && $navParams,
			"order" => $arSort,
			"runtime" => $arRuntime
		]);

		if ($showNav && $navParams) {
			$navParams->setRecordCount($dbItems->getCount());
		}

		return [$this->formatItems($dbItems, $debug), $navParams];
	}

	/**
	 * @param array $arItems
	 * @param bool $debug
	 * @return array
	 */
	protected function getSectionPropertiesElements(array $arItems = [], bool $debug = false) : array
	{
		$sections = [];
		foreach($arItems as $arItem) {
			foreach($arItem['PROPERTIES'] as $property) {
				if ($property['PROPERTY_TYPE'] !== 'G' || !$property['VALUE']) continue;

				if (!is_array($property['VALUE'])) {
					$property['VALUE'] = [$property['VALUE']];
				}

				$sections = array_merge($sections, $property['VALUE']);
			}
		}
		$sections = array_unique($sections);

		if (empty($sections)) return $arItems;

		$rsSection = \Bitrix\Iblock\SectionTable::getList([
			'filter' => [
				'ID' => $sections,
				'ACTIVE' => 'Y',
				'GLOBAL_ACTIVE' => 'Y'
			],
			'select' => ['*']
		])->fetchAll();

		$sectionMap = [];
		foreach ($rsSection as $section) {
			$sectionMap[$section['ID']] = $section;
		}

		foreach($arItems as &$arItem) {
			foreach($arItem['PROPERTIES'] as &$property) {
				if ($property['PROPERTY_TYPE'] !== 'G' || !$property['VALUE']) continue;

				if (is_array($property['VALUE'])) {
					foreach($property['VALUE'] as $i => $value) {
						if (isset($sectionMap[$value])) {
							$property['VALUE'][$i] = $sectionMap[$value];
						}
					}
				}
				else {
					if (isset($sectionMap[$property['VALUE']])) {
						$property['VALUE'] = $sectionMap[$property['VALUE']];
					}
				}
			}
		}

		return $arItems;
	}

	protected function getUserPropertiesElements(array $arItems = [], bool $debug = false) : array
	{
		$users = [];
		$arItemKeys = [];

		foreach ($arItems as $key => $arItem) {
			foreach ($arItem['PROPERTIES'] as $code => $property) {
				if ($property['USER_TYPE'] !== self::USER_TYPE_USER || empty($property['VALUE'])) {
					continue;
				}

				if (is_array($property['VALUE'])) {
					$users = array_merge($users, $property['VALUE']);
				} else {
					$users[] = $property['VALUE'];
				}

				$arItemKeys[$key][$code] = $property['VALUE'];
			}
		}

		$users = array_unique($users);

		if (empty($users)) {
			return $arItems;
		}

		$dbUsers = \Bitrix\Main\UserTable::getList([
			'filter' => [
				'ID' => $users,
				'ACTIVE' => 'Y',
				'BLOCKED' => 'N'
			],
			'select' => ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'EMAIL', 'LOGIN']
		])->fetchAll();

		if (empty($dbUsers)) {
			return $arItems;
		}

		$userMap = [];
		foreach ($dbUsers as $user) {
			$userMap[$user['ID']] = $user;
		}

		foreach ($arItemKeys as $key => $data) {
			if (!isset($arItems[$key])) {
				continue;
			}

			foreach ($data as $code => $value) {
				if (!isset($arItems[$key]['PROPERTIES'][$code])) {
					continue;
				}

				if (is_array($arItems[$key]['PROPERTIES'][$code]['VALUE'])) {
					foreach ($arItems[$key]['PROPERTIES'][$code]['VALUE'] as $i => $userId) {
						if (isset($userMap[$userId])) {
							$arItems[$key]['PROPERTIES'][$code]['VALUE'][$i] = $userMap[$userId];
						}
					}
				} else {
					if (isset($userMap[$arItems[$key]['PROPERTIES'][$code]['VALUE']])) {
						$arItems[$key]['PROPERTIES'][$code]['VALUE'] = $userMap[$arItems[$key]['PROPERTIES'][$code]['VALUE']];
					}
				}
			}
		}

		return $arItems;
	}

	/**
	 * Retrieve the elements of the iblock properties.
	 *
	 * @param array $arItems The array of items to retrieve properties from
	 * @param bool $debug Whether to enable debugging
	 * @return array The elements of the iblock properties
	 * @throws Exception
	 */
	protected function getIblockPropertiesElements(array $arItems = [], bool $debug = false) : array
	{
		if (empty($arItems)) return $arItems;

		$ids = [];

		foreach ($arItems as $arItem) {
			foreach ($arItem['PROPERTIES'] as $property) {
				$this->setFileIdsFromProperty($property);

				$isIblockProperty = $property['LINK_IBLOCK_ID'] > 0 || $property['PROPERTY_TYPE'] == 'E';

				if ($isIblockProperty && $property['VALUE']) {
					$ids = array_merge($ids, (array) $property['VALUE']);
				}
			}
		}

		$ids = array_unique(array_filter($ids, function($id) {
			return $id > 0;
		}));

		if (empty($ids)) {
			return [];
		}

		$filter = ElementsFilter::getInstance();
		$filter->add('ID', $ids);
		$filter->add('ACTIVE', 'Y');

		[$elements, $newNavParams] = $this->getItems($filter, false, false, true);

		$iblockIds = array_unique(array_column($elements, 'IBLOCK_ID'));

		$elements = $this->getProperties($iblockIds, $elements, []);

		foreach ($elements['PROPERTIES'] as $arProperty) {
			$this->setFileIdsFromProperty($arProperty);
		}

		return $elements;
	}

	/**
	 * Retrieves the elements of the HLBlock properties.
	 *
	 * @param array $arItems The array of items to retrieve the HLBlock properties elements for.
	 * @return array The array of items with the HLBlock properties elements retrieved.
	 * @throws Main\LoaderException
	 */
	protected function getHLBlockPropertiesElements(array $arItems = []) : array
	{
		if (empty($arItems)) return $arItems;

		$hlDbs = [];
		foreach($this->HLDataGenerator($arItems) as $table) {
			$hlDbs[$table['TABLE']]['VALUE'] = array_unique(array_merge($hlDbs[$table['TABLE']]['VALUE'] ?? [], $table['VALUE']));
			$hlDbs[$table['TABLE']]['ID'][$table['ID']][$table['PROPERTY_CODE']] = $table['VALUE'];
		}

		if (empty($hlDbs) || !\Bitrix\Main\Loader::includeModule("highloadblock")) {
			return $arItems;
		}

		$tableNames = array_keys($hlDbs);
		$allTables = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'filter' => [
				'TABLE_NAME' => $tableNames
			]
		])->fetchAll();

		foreach($allTables as $table) {
			$tableName = $table['TABLE_NAME'];
			if (!empty($hlDbs[$tableName]['VALUE'])) {
				$entityDataClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($table)->getDataClass();
				$result = $entityDataClass::getList([
					'filter' => [
						'UF_XML_ID' => $hlDbs[$tableName]['VALUE']
					]
				])->fetchAll();

				foreach($result as $res) {
					$hlDbs[$tableName]['RESULT'][$res['UF_XML_ID']] = $res;
				}
			}
		}

		foreach($hlDbs as $table) {
			foreach($table['ID'] as $id => $value) {
				$key = array_search($id, array_column($arItems, 'ID'));

				if ($key !== false) {
					foreach($value as $propCode => $propValue) {
						$elementPropertyValue = $arItems[$key]['PROPERTIES'][$propCode]['VALUE'];

						if(is_string($elementPropertyValue) && $elementPropertyValue) {
							$arItems[$key]['PROPERTIES'][$propCode]['VALUE'] = $table['RESULT'][$elementPropertyValue];
						}
						elseif (is_array($elementPropertyValue)) {
							$arItems[$key]['PROPERTIES'][$propCode]['VALUE'] = array_map(function($elementHLValue) use ($table) {
								return $table['RESULT'][$elementHLValue];
							}, $elementPropertyValue);
						}
					}
				}
			}
		}

		return $arItems;
	}

	protected function HLDataGenerator($arItems = []) {
		foreach($arItems as $arItem) {
			foreach($arItem['PROPERTIES'] as $code => $property) {
				$userTypeSettings = $property['USER_TYPE_SETTINGS'];
				if (is_array($userTypeSettings) && $userTypeSettings['TABLE_NAME']) {
					yield([
						'TABLE' => $userTypeSettings['TABLE_NAME'],
						'VALUE' => is_array($property['VALUE']) ? $property['VALUE'] : [$property['VALUE']],
						'ID' => $arItem['ID'],
						'PROPERTY_CODE' => $code
					]);
				}
			}
		}
	}

	protected function getSectionData()
	{
		if (count($this->arResult["ITEMS"]) > 0 && $this->arParams['SECTION_ID'] > 0) {
			$this->arResult['SECTION_ID'] = $this->arParams['SECTION_ID'];

			$section = [];
			foreach($this->arResult["ITEMS"] as $arItem) {
				if ($arItem['SECTION']['ID'] == $this->arParams['SECTION_ID']) {
					$this->arResult['SECTION'] = $arItem['SECTION'];
					break;
				}
			}

			if ($this->arResult['SECTION']['PAGE_URL']) {
				$section['SECTION_CODE'] = $this->arResult['SECTION'];
				$this->arResult['SECTION']['PAGE_URL'] = \CIBlock::ReplaceSectionUrl($this->arResult['SECTION']['PAGE_URL'], $section, false, 'E') ?: '';
			}

		}
	}

	protected function getFilesArrayFromItems() : array
	{
		// Собираем массив с файлами одним запросом (превью и детальные картинки)
		$fileArray = $this->getFilesArray($this->fileIdsArray);

		return $fileArray ?: [];
	}

	protected function getFilesArray($ids) : array
	{
		$array = [];

		// Собираем массив с файлами одним запросом (превью и детальные картинки)
		if (is_array($ids) && !empty($ids)) {
			$uploadDir = \COption::GetOptionString("main", "upload_dir", "upload");
			$dbFiles = \CFile::GetList([], ['@ID' => $ids]);
			while($dbFile = $dbFiles->fetch()) {
				$dbFile['SRC'] = "/".$uploadDir."/".$dbFile["SUBDIR"]."/".$dbFile["FILE_NAME"];

				unset($dbFile['TIMESTAMP_X']);
				unset($dbFile['MODULE_ID']);
				unset($dbFile['HEIGHT']);
				unset($dbFile['WIDTH']);
				unset($dbFile['SUBDIR']);
				unset($dbFile['HANDLER_ID']);
				unset($dbFile['EXTERNAL_ID']);

				if (!$dbFile['DESCRIPTION']) unset($dbFile['DESCRIPTION']);

				$array[$dbFile['ID']] = $dbFile;
			}
		}

		return $array;
	}

	protected function setNavData($navParams) : array
	{
		if ($navParams) {
			 return [
				"NavPageCount"      => $navParams->getPageCount(),
				"NavPageSize"       => $navParams->getPageSize(),
				"NavNum"            => $navParams->getId(),
				"NavPageNumber"     => $navParams->getCurrentPage(),
				"NavRecordCount"    => $navParams->getRecordCount(),
			];
		}

		return [];
	}

	/**
	 * Получить список свойств для элементов
	 * @param int|array $iblockId
	 * @param array $arItems
	 * @param array $propertyCodes
	 * @return array
	 */
	protected function getProperties(int|array $iblockId, array $arItems = [], array $propertyCodes = []) : array
	{
		if (empty($arItems)) return [];

		$elementIds = array_map(function($item) {
			return $item['ID'];
		}, $arItems);

		$arFilter = [
			"IBLOCK_ELEMENT_ID" => $elementIds,
			"PROPERTY.IBLOCK_ID" => $iblockId,
			"PROPERTY.ACTIVE" => "Y"
		];

		if (!empty($propertyCodes)) {
			$arFilter["PROPERTY.CODE"] = $propertyCodes;
		}

		$propertiesValues = \Bitrix\Iblock\ElementPropertyTable::getList([
			"select" => [
				"PROPERTY_VALUE_ID" => "ID",
				"IBLOCK_ELEMENT_ID",
				"PROPERTY_ID" => "IBLOCK_PROPERTY_ID",
				"CODE" => "PROPERTY.CODE",
				"VALUE",
				"VALUE_TYPE",
				"VALUE_ENUM",
				"DESCRIPTION",

				"PROPERTY.LINK_IBLOCK_ID",
				"PROPERTY.MULTIPLE",
				"PROPERTY.NAME",
				"PROPERTY.PROPERTY_TYPE",
				"PROPERTY.FILE_TYPE",
				"PROPERTY.IBLOCK_ID",
				"PROPERTY.USER_TYPE_SETTINGS",
				"PROPERTY.USER_TYPE",
				"ENUM_XML_ID" => "ENUM.XML_ID",
				"ENUM_VALUE" => "ENUM.VALUE",
			],
			"filter" => $arFilter,
			"runtime" => [
				"PROPERTY" => [
					'data_type' => '\Bitrix\Iblock\PropertyTable',
					'reference' => [
						'=this.IBLOCK_PROPERTY_ID' => 'ref.ID',
					],
					'join_type' => "INNER"
				],
				"ENUM" => [
					'data_type' => '\Bitrix\Iblock\PropertyEnumerationTable',
					'reference' => [
						'=this.IBLOCK_PROPERTY_ID' => 'ref.PROPERTY_ID',
						'=this.VALUE_ENUM' => 'ref.ID',
					],
					'join_type' => "LEFT"
				]
			]
		])->fetchAll();

		$arProperties = [];
		foreach($propertiesValues as $propertyValue) {
			$property = [];
			$iblockPropertyCode = 'IBLOCK_ELEMENT_PROPERTY_PROPERTY_';

			foreach($propertyValue as $fieldCode => $fieldValue) {
				if (stripos($fieldCode, $iblockPropertyCode) !== false) {
					$fieldCode = str_ireplace($iblockPropertyCode, '', $fieldCode);
				}
				$property[$fieldCode] = $fieldValue;
			}

			unset($property['VALUE_ENUM']);

			if ($property['MULTIPLE'] === 'Y') {
				$property['PROPERTY_VALUE_ID'] = [$property['PROPERTY_VALUE_ID']];
				$property['VALUE'] = [$property['VALUE']];
				$property['DESCRIPTION'] = [$property['DESCRIPTION']];
				$property['ENUM_VALUE'] = [$property['ENUM_VALUE']];
				$property['ENUM_XML_ID'] = [$property['ENUM_XML_ID']];
			}

			asort($property, SORT_NATURAL);

			$code = $property['CODE'];

			if ($property['USER_TYPE_SETTINGS']) {
				$property['USER_TYPE_SETTINGS'] = unserialize($property['USER_TYPE_SETTINGS']);
			}

			$propertyIblockElementId = $property['IBLOCK_ELEMENT_ID'];

			$this->setFileIdsFromProperty($property);

			$property['VALUE'] = $this->getUnserializedValue($property['VALUE']);

			if ($property['MULTIPLE'] === 'Y') {
				if (!$arProperties[$propertyIblockElementId][$code]) {
					$arProperties[$propertyIblockElementId][$code] = $property;
				}
				else {
					if (is_array($property['VALUE']) && !empty($property['VALUE'])) {
						$arProperties[$propertyIblockElementId][$code]['PROPERTY_VALUE_ID'] = array_merge(
							$arProperties[$propertyIblockElementId][$code]['PROPERTY_VALUE_ID'],
							$property['PROPERTY_VALUE_ID']
						);

						$arProperties[$propertyIblockElementId][$code]['VALUE'] = array_merge(
							$arProperties[$propertyIblockElementId][$code]['VALUE'],
							$property['VALUE']
						);
						$arProperties[$propertyIblockElementId][$code]['DESCRIPTION'] = array_merge(
							$arProperties[$propertyIblockElementId][$code]['DESCRIPTION'],
							$property['DESCRIPTION']
						);
					}
					elseif(is_array($property['ENUM_VALUE']) && !empty($property['ENUM_VALUE'])) {
						$arProperties[$propertyIblockElementId][$code]['PROPERTY_VALUE_ID'] = array_merge(
							$arProperties[$propertyIblockElementId][$code]['PROPERTY_VALUE_ID'],
							$property['PROPERTY_VALUE_ID']
						);
						$arProperties[$propertyIblockElementId][$code]['ENUM_VALUE'] = array_merge(
							$arProperties[$propertyIblockElementId][$code]['ENUM_VALUE'],
							$property['ENUM_VALUE']
						);
						$arProperties[$propertyIblockElementId][$code]['ENUM_XML_ID'] = array_merge(
							$arProperties[$propertyIblockElementId][$code]['ENUM_XML_ID'],
							$property['ENUM_XML_ID']
						);
					}
					else {
						$arProperties[$propertyIblockElementId][$code]['PROPERTY_VALUE_ID'][] = $property['PROPERTY_VALUE_ID'];
						if ($property['VALUE']) {
							$arProperties[$propertyIblockElementId][$code]['VALUE'][] = $property['VALUE'];
							$arProperties[$propertyIblockElementId][$code]['DESCRIPTION'][] = $property['DESCRIPTION'];
						}
						if ($property['ENUM_VALUE']) {
							$arProperties[$propertyIblockElementId][$code]['ENUM_VALUE'][] = $property['ENUM_VALUE'];
							$arProperties[$propertyIblockElementId][$code]['ENUM_XML_ID'][] = $property['ENUM_XML_ID'];
						}
					}
				}
			}
			else {
				$arProperties[$propertyIblockElementId][$code] = $property;
			}
		}

		foreach($arItems as &$arItem) {
			$arItem['PROPERTIES'] = $arProperties[$arItem['ID']];
		}

		return $arItems;
	}

	/**
	 * @param $value
	 * @return array|mixed
	 */
	protected function getUnserializedValue($value)
	{
		if (!$value) {
			return $value;
		}

		if (is_array($value)) {
			return array_map(function($item) {
				$decoded = unserialize($item);
				return $decoded ?: $item;
			}, $value);
		}

		$decoded = unserialize($value);
		return $decoded ?: $value;
	}

	/**
	 * Построить навигационную цепочку
	 * @throws ArgumentException
	 * @noinspection PhpUnused
	 */
	protected function getNavCHain() : array
	{
		$iblockId = $this->arParams['IBLOCK_ID'];
		$sectionId = $this->arResult['ITEMS'][0]['SECTION']['ID'];

		$navChain = \CIBlockSection::GetNavChain($iblockId, $sectionId, ["ID", "IBLOCK_ID", "NAME", "SECTION_PAGE_URL"], true);
		foreach($navChain as &$chainItem) {
			$arItem = $chainItem;
			$arItem['SECTION_CODE'] = $chainItem['CODE'];
			$sectionPath = \CIBlock::ReplaceSectionUrl($arItem['SECTION_PAGE_URL'], $arItem, false, 'E') ?: '';
			$chainItem['SECTION_PAGE_URL'] = $sectionPath;
		}

		return $navChain;
	}

	/**
	 * Формирует SELECT для выборки элементов
	 * @return array|string[]
	 */
	protected function getSelect() : array
	{
		$paramsSelect = $this->arParams["ADDITIONAL_SELECT"];

		$setDetailUrl = $this->arParams["SET_DETAIL_URL"] === "Y";

		if ($setDetailUrl) {
			$this->defaultSelect = array_merge(
				$this->defaultSelect,
				[
					"IBLOCK.ID",
					"IBLOCK.LIST_PAGE_URL",
					"IBLOCK.SECTION_PAGE_URL",
					"IBLOCK.DETAIL_PAGE_URL",

					"IBLOCK_SECTION.ID",
					"IBLOCK_SECTION.CODE",
				]
			);
		}

		if (!$paramsSelect) {
			return $this->defaultSelect;
		}
		else {
			if (in_array("*", $paramsSelect)) {
				return ["*"];
			}
			return array_merge($this->defaultSelect, $paramsSelect);
		}
	}


	protected function setFileIdsFromProperty($property)
	{
		if ($property['PROPERTY_TYPE'] === 'F' && is_array($property['VALUE'])) {
			$this->fileIdsArray = array_merge($this->fileIdsArray, $property['VALUE']);
		}
		elseif ($property['PROPERTY_TYPE'] === 'F' && $property['VALUE']) {
			$this->fileIdsArray[] = $property['VALUE'];
		}
	}

	protected function setUrlTemplates($arItem) : array
	{
		$strListUrl = $this->arParams["LIST_URL"] ?: $arItem['IBLOCK']['LIST_PAGE_URL'];
		$strSectionUrl = $this->arParams["SECTION_URL"] ?: $arItem['IBLOCK']['SECTION_PAGE_URL'];
		$strDetailUrl = $this->arParams["DETAIL_URL"] ?: $arItem['IBLOCK']['DETAIL_PAGE_URL'];

		if ($arItem['SECTION']) {
			$arItem['SECTION_ID'] = $arItem['SECTION']['ID'];
			$arItem['SECTION_CODE'] = $arItem['SECTION']['CODE'];
		}

		$strListUrl = \CIBlock::ReplaceDetailUrl($strListUrl, $arItem, false, 'E') ?: '';
		$strSectionUrl = \CIBlock::ReplaceSectionUrl($strSectionUrl, $arItem, false, 'E') ?: '';
		$strDetailUrl = \CIBlock::ReplaceDetailUrl($strDetailUrl, $arItem, false, 'E') ?: '';

		return array_filter(
			[
				"LIST_PAGE_URL" => $strListUrl,
				"SECTION_PAGE_URL" => $strSectionUrl,
				"DETAIL_PAGE_URL" => $strDetailUrl,
			]
		);
	}

	/**
	 * @return array
	 */
	protected function setMeta() : array
	{
		global $APPLICATION;
		$params = $this->arParams;
		$result = $this->arResult;
		$iblockId = $params['IBLOCK_ID'];

		if ($params["SET_META"] !== "Y") {
			return [];
		}

		if ($result['SECTION_ID'] > 0) {
			$ipropValues = new \Bitrix\Iblock\InheritedProperty\SectionValues($iblockId, $result['SECTION_ID']);
			$arSEO = $ipropValues->getValues();

			if ($arSEO['SECTION_META_TITLE'] != false) {
				$APPLICATION->SetPageProperty("title", $arSEO['SECTION_META_TITLE']);
			}

			if ($arSEO['SECTION_META_KEYWORDS'] != false) {
				$APPLICATION->SetPageProperty("keywords", $arSEO['SECTION_META_KEYWORDS']);
			}
			if ($arSEO['SECTION_META_DESCRIPTION'] != false) {
				$APPLICATION->SetPageProperty("description", $arSEO['SECTION_META_DESCRIPTION']);
			}
		}
		else {
			$ipropValues = new \Bitrix\Iblock\InheritedProperty\IblockValues($iblockId);
			$arSEO = $ipropValues->getValues();

			if ($arSEO['ELEMENT_META_TITLE'] != false) {
				$APPLICATION->SetPageProperty("title", $arSEO['ELEMENT_META_TITLE']);
			}

			if ($arSEO['ELEMENT_META_KEYWORDS'] != false) {
				$APPLICATION->SetPageProperty("keywords", $arSEO['ELEMENT_META_KEYWORDS']);
			}
			if ($arSEO['ELEMENT_META_DESCRIPTION'] != false) {
				$APPLICATION->SetPageProperty("description", $arSEO['ELEMENT_META_DESCRIPTION']);
			}
		}

		return $arSEO;
	}

	/**
	 * @param Result $dbItems
	 * @param bool $debug
	 * @return array
	 */
	protected function formatItems(Result $dbItems, bool $debug = false) : array
	{
		$formatDate = $this->arParams["FORMAT_DATE"] !== "N"; //Форматировать ли дату
		$dateFormat = $this->arParams["DATE_FORMAT"];
		$setDetailUrl = $this->arParams["SET_DETAIL_URL"] === "Y";

		$arItems = [];
		foreach($dbItems as $dbItem) {
			$arItem = [];

			$hasSubsection = $dbItem['IBLOCK_SECTION_ID'] > 0;
			$iblockCode = 'IBLOCK_ELEMENT_IBLOCK_';
			$iblockSectionCode = 'IBLOCK_ELEMENT_IBLOCK_SECTION_';

			$arItem['ID'] = $dbItem['ID'];
			$arItem['IBLOCK_ID'] = $dbItem['IBLOCK_ID'];
			$arItem['IBLOCK_SECTION_ID'] = $dbItem['IBLOCK_SECTION_ID'];
			$arItem['DETAIL_PAGE_URL'] = '';

			foreach($dbItem as $fieldCode => $fieldValue) {
				$elementField = true;

				//Отделяем поля раздела
				if ($hasSubsection && $fieldCode && stripos($fieldCode, $iblockSectionCode) !== false) {
					$elementField = false;
					$fieldCode = str_ireplace($iblockSectionCode, '', $fieldCode);
					$arItem['SECTION'][$fieldCode] = $fieldValue;
				}
				else if (!$hasSubsection && stripos($fieldCode, $iblockSectionCode) !== false) {
					$arItem['SECTION'] = [];
					continue;
				}

				//Отделяем поля инфоблока
				if (stripos($fieldCode, $iblockCode) !== false) {
					$elementField = false;
					$fieldCode = str_ireplace($iblockCode, '', $fieldCode);
					$arItem['IBLOCK'][$fieldCode] = $fieldValue;
				}

				if ($fieldCode === 'ACTIVE_FROM' && $fieldValue && $formatDate) {
					$arItem['DATE_ACTIVE_FROM'] = $fieldValue->format($this->defaultDateFormat);
					$arItem['TIMESTAMP_ACTIVE_FROM'] = $fieldValue->getTimestamp();
					$arItem['ACTIVE_FROM_UNIX'] = $fieldValue->getTimestamp();
				}

				if ($fieldCode === 'ACTIVE_TO' && $fieldValue && $formatDate) {
					$arItem['DATE_ACTIVE_TO'] = $fieldValue->format($dateFormat);
					$arItem['TIMESTAMP_ACTIVE_TO'] = $fieldValue->getTimestamp();
					$arItem['ACTIVE_TO_UNIX'] = $fieldValue->getTimestamp();
				}

				if ($elementField) {
					$arItem[$fieldCode] = $fieldValue;
				}
			}

			if ($setDetailUrl) {
				$urlTemplates = $this->setUrlTemplates($arItem);
				$arItem = array_merge($arItem, $urlTemplates);
			}

			$arItem['PROPERTIES'] = [];

			if (empty($arItem['SECTION'])) {
				unset($arItem['IBLOCK_SECTION_ID']);
				unset($arItem['SECTION']);
			}

			if ($arItem['PREVIEW_PICTURE']) $this->fileIdsArray[] = $arItem['PREVIEW_PICTURE'];

			$arItems[] = $arItem;
		}

		return $arItems;
	}

    /**
     * подготовка данных по кнопкам эрмитажа для режима правки
     */
    protected function initEditButtons()
    {
        if ($this->iblockId <= 0) {
            return;
        }

        if (!$this->arParams["DESIGN_MODE"]) {
            return;
        }

        $arButtons = \CIBlock::GetPanelButtons(
            $this->arParams["IBLOCK_ID"],
            0,
            0,
            ["SECTION_BUTTONS" => false, "SESSID" => false]
        );

        $this->arResult["ADD_LINK"] = $arButtons["edit"]["add_element"]["ACTION_URL"];

        if (!empty($this->arResult["ITEMS"])) {
            foreach ($this->arResult["ITEMS"] as &$arItem) {
                $arButtons = CIBlock::GetPanelButtons(
                    $this->arParams["IBLOCK_ID"],
                    $arItem["ID"],
                    0,
                    ["SECTION_BUTTONS" => false, "SESSID" => false]
                );
                $arItem["EDIT_LINK"] = $arButtons["edit"]["edit_element"]["ACTION_URL"];
                $arItem["DELETE_LINK"] = $arButtons["edit"]["delete_element"]["ACTION_URL"];
            }
        }

        unset($arItem);
    }

	/**
	 * Создает объект пагинации
	 */
	protected function initNavData() : PageNavigation
	{
		if (!$this->limit) $this->limit = 150;
		if (!$this->navigationName) $this->navigationName = "nav";

		$navParams = new PageNavigation($this->navigationName);
		$navParams->allowAllRecords(true)->setPageSize($this->limit)->initFromUri();
		return $navParams;
	}

    /**
     * кеширует ключи массива arResult
     */
    protected function putDataToCache()
    {
        if (is_array($this->resultCacheKeys) && sizeof($this->resultCacheKeys) > 0) {
            $this->SetResultCacheKeys($this->resultCacheKeys);
        }
    }

    /**
     * формируем набор кнопок для эрмитажа в режиме правки
     */
    protected function showEditButtons()
    {
        global $APPLICATION;
		$params = $this->arParams;

        if ($params["IBLOCK_ID"] <= 0) {
            return;
        }

        if (!$params["DESIGN_MODE"]) {
            return;
        }

        $arButtons = CIBlock::GetComponentMenu(
            $APPLICATION->GetPublicShowMode(),
            CIBlock::GetPanelButtons(
                $this->iblockId,
                0,
                $this->arResult["SECTION_ID"],
                ["SECTION_BUTTONS" => true]
            )
        );

        array_walk($arButtons, [$this, "AddIncludeAreaIcon"]);
    }

    /**
     * прерывает кеширование
     */
    protected function abortDataCache()
    {
        $this->AbortResultCache();
    }
}
