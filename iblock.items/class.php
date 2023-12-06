<?php

namespace Tryhardy\Components;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

// composer for out public
if (file_exists($_SERVER["DOCUMENT_ROOT"]."/../vendor/autoload.php")) {
	require_once($_SERVER["DOCUMENT_ROOT"] . "/../vendor/autoload.php");
}

// composer for local
if (file_exists($_SERVER["DOCUMENT_ROOT"]."/local/vendor/autoload.php")) {
	require_once($_SERVER["DOCUMENT_ROOT"] . "/local/vendor/autoload.php");
}

use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Iblock;
use Bitrix\Main\UI\PageNavigation;
use CBitrixComponent;
use CDBResult;
use CIBlock;
use CPageOption;
use Exception;
use Tryhardy\BitrixFilter\ElementsFilter;
use Uplab\Core\IblockHelper;

class TryhardyIblockItemsComponent extends CBitrixComponent
{
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
    ];

	//массив параметров для select по умолчанию
    private array $defaultSelect = [
		"ID",
		"IBLOCK_ID",
		"NAME",
	    "CODE",
	    "SORT",
	    "TIMESTAMP_X",
	    "DATE_CREATE",
	    "IBLOCK_SECTION_ID",
	    "ACTIVE",
	    "ACTIVE_FROM",
	    "ACTIVE_TO",
	    "PREVIEW_TEXT",
	    "PREVIEW_TEXT_TYPE",
	    "DETAIL_TEXT",
	    "DETAIL_TEXT_TYPE",
	    "PREVIEW_PICTURE",
	    "DETAIL_PICTURE",

	    "IBLOCK.ID",
	    "IBLOCK.NAME",
	    "IBLOCK.CODE",
	    "IBLOCK.LIST_PAGE_URL",
	    "IBLOCK.SECTION_PAGE_URL",
	    "IBLOCK.DETAIL_PAGE_URL",

	    "IBLOCK_SECTION.ID",
	    "IBLOCK_SECTION.ACTIVE",
	    "IBLOCK_SECTION.GLOBAL_ACTIVE",
	    "IBLOCK_SECTION.NAME",
	    "IBLOCK_SECTION.CODE",
	    "IBLOCK_SECTION.PICTURE",
	    "IBLOCK_SECTION.DEPTH_LEVEL",
	    "IBLOCK_SECTION.DESCRIPTION",
	    "IBLOCK_SECTION.IBLOCK_SECTION_ID",
    ];

    /**
     * дополнительные параметры, от которых должен зависеть кэш
     * (filter query, user id, section id, etc)
     * @var array
     */
    protected array $cacheAddon = [];

    //модули, которые необходимо подключить для корректной работы компонента
    protected array $dependModules = ["iblock", "uplab.core"];

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
	protected int $maxLimit = 100;
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
    protected $filter = null;                           //внешний фильтр
    protected array $arSort = [];                           //внешняя сортировка
	protected bool $useRandomSort = false;                  //Если в SORT передается параметр RAND

    protected bool $fromCache = true;                       //флаг использования кэша

	protected string $defaultDateFormat = 'd.m.Y';          //формат даты по умолчанию

    /**
     * подключаем языковые файлы
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
    public function onPrepareComponentParams(array $params = []): array
    {
		//под админом в режиме правки?
        $bDesignMode = $GLOBALS["APPLICATION"]->GetShowIncludeAreas() && is_object($GLOBALS["USER"]) && ($GLOBALS["USER"]->IsAdmin());

		//Устанавливаем срок жизни кэша, если не указан
        if (!isset($params["CACHE_TIME"]) && !$bDesignMode) {
            if (defined("CACHE_TIME")) {
                $params["CACHE_TIME"] = CACHE_TIME;
            }
			else {
                $params["CACHE_TIME"] = 0;
            }
        }

		//Устанавливаем количество элементов на странице (минимум 1, максимум 100)
        $params["COUNT_LIMIT"] = (int) $params["COUNT_LIMIT"];
        if ($params["COUNT_LIMIT"] <= 0 || $params["COUNT_LIMIT"] > $this->maxLimit) {
            $params["COUNT_LIMIT"] = $this->limit;
        }

		//Пробрасываем исключение, если в параметры компонента передается неправильный объект фильтра
        if (
			isset($params["FILTER"]) &&
			!empty($params["FILTER"]) &&
			!$params["FILTER"] instanceof ElementsFilter
        ) {
	        throw new Exception("FILTER object not found");
        }
		else {
			$params["FILTER"] = ElementsFilter::getInstance();
		}

        if (isset($params["ADDITIONAL_SELECT"]) && !empty($params["ADDITIONAL_SELECT"])) {
            foreach ($params["ADDITIONAL_SELECT"] as $selectItem) {
	            $params["ADDITIONAL_SELECT"][] = trim($selectItem);
            }
        }

        $result = [
	        "DESIGN_MODE" => $bDesignMode,
	        "IBLOCK_ID" => intval($params["IBLOCK_ID"]),
	        "SECTION_ID" => intval($params["SECTION_ID"]),
	        "PAGER_TITLE" => trim($params["PAGER_TITLE"]),
	        "DETAIL_URL" => trim($params["DETAIL_URL"]),
	        "SECTION_URL" => trim($params["SECTION_URL"]),
	        "LIST_URL" => trim($params["LIST_URL"]),
	        "PAGER_TEMPLATE" => trim($params["PAGER_TEMPLATE"]),
	        "PAGER_SHOW_ALWAYS" => ($params["PAGER_SHOW_ALWAYS"] == "Y" ? "Y" : "N"),
	        "SHOW_NAV" => ($params["SHOW_NAV"] == "N" ? "N" : "Y"),
	        "SET_META" => ($params["SET_META"] == "Y" ? "Y" : "N"),
	        "SET_CHAIN" => ($params["SET_CHAIN"] == "Y" ? "Y" : "N"),
	        "COUNT_LIMIT" => $params["COUNT_LIMIT"],
	        "CACHE_GROUPS" => ($params["CACHE_GROUPS"] == "Y" ? "Y" : "N"),
	        "RETURN_ITEMS" => ($params["RETURN_ITEMS"] == "Y" ? "Y" : "N"),
	        "SET_STATUS_404" => ($params["SET_STATUS_404"] == "Y" ? "Y" : "N"),
	        "SECTIONS_SELECT" => array_unique(
		        array_merge(
			        $params["SECTIONS_SELECT"] ?? [],
			        [
				        "DESCRIPTION",
				        "SECTION_PAGE_URL",
			        ]
		        )
	        ),
        ];

        return array_merge($params, $result);
    }

	protected function onPrepareClassProperties(array $params = [])
	{
		if ($params["CACHE_TIME"] > 0) {
			$this->fromCache = true;
		}

		$this->onPrepareFilter($params);
		$this->onPrepareSort($params);

		$this->showNav = $this->arParams["SHOW_NAV"] === "Y";
		$this->limit = $this->arParams["COUNT_LIMIT"] > 0 ? $this->arParams["COUNT_LIMIT"] : $this->limit;
		$this->navigationName = $this->arParams["NAVIGATION_NAME"] ?: ($this->getTemplateName() ?: 'pagination');

	}

	protected function onPrepareFilter(array $params = [])
	{
		$this->filter = $params["FILTER"];

		if (!$this->filter->get("IBLOCK_ID")) {
			$this->filter->add("IBLOCK_ID", $params["IBLOCK_ID"]);
		}

		if ($params["SECTION_ID"] > 0) {
			$includeSubsections = !($params["INCLUDE_SUBSECTIONS"] == "N");
			$this->filter = $this->filter->addSections("IBLOCK_SECTION_ID", (int) $params["SECTION_ID"], $includeSubsections);
		}

		if ($params["SECTION_GLOBAL_ACTIVE"] === "Y") {
			$this->filter = $this->filter->add("IBLOCK_SECTION.GLOBAL_ACTIVE", $params["SECTION_GLOBAL_ACTIVE"]);
		}
	}

	protected function onPrepareSort(array $params = [])
	{
		if (isset($params["SORT"]) && !empty($params["SORT"]) && is_array($params["SORT"])) {
			$this->arSort = $params["SORT"];
			if (in_array("RAND", array_keys($this->arSort))) $this->useRandomSort = true;
		}
	}

    public function executeComponent()
    {
        try {
			$params = $this->arParams;
	        $this->checkParams($params);
			$this->onPrepareClassProperties($params);
	        $this->checkModules();
	        $this->initNavData();

            if (!$this->readDataFromCache()) {
				$this->getResult();
				$result = $this->arResult;

                if (defined("BX_COMP_MANAGED_CACHE")) {
                    global $CACHE_MANAGER;
                    $CACHE_MANAGER->RegisterTag("iblock_id_" . $params["IBLOCK_ID"]);
                }

                if (empty($result["ITEMS"]) && $params["SET_STATUS_404"] === "Y") {
                    $this->abortResultCache();

                    \Bitrix\Iblock\Component\Tools::process404(
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

            if (isset($this->arResult["__RETURN_VALUE"])) {
                return $this->arResult["__RETURN_VALUE"];
            }

            return $this->arResult;
        }
		catch (Exception $e) {
            $this->abortDataCache();
            ShowError($e->getMessage());
        }
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
					Loc::getMessage("ITEMS_LIST_MODULE_NOT_FOUND") . " class.php" . $module
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
    protected function readDataFromCache()
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
    protected function endDataCache()
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
	 * формируем список элементов
	 */
	protected function getResult()
	{
		$useTilda = true;
		$arSort = $this->arSort ?: [];
		$iblockId = $this->arParams["IBLOCK_ID"];
		$propertyCodes = $this->arParams["ADDITIONAL_PROPERTIES"] ?: [];
		$getProperties = $this->arParams["SHOW_PROPERTIES"] !== 'N';
		$hidePicture = $this->arParams["HIDE_PICTURES"] === "Y";
		$showNavCHain = $this->arParams["SHOW_SECTIONS_CHAIN"] === "Y";

		$this->arResult = [
			"TITLE" => "",
			"DESCRIPTION" => "",
			"IBLOCK_ID" => $iblockId,
			"SECTION_ID" => $this->arParams['SECTION_ID'],
			"SECTION" => [],
			"NAV_CHAIN" => [],
			"FILTER" => [],
			"SORT" => $arSort,
			"NAV_DATA" => [],
			"ITEMS" => [],
			"__RETURN_VALUE" => null,
		];

		//Получаем список элементов
		$arItems = $this->getItems($iblockId);

		//Получаем свойства элементов
		if ($getProperties) {
			$arItems = $this->getProperties($arItems, $propertyCodes);
		}

		$fileArray = [];
		if (!$hidePicture) {
			//get files array from items
			$fileArray = $this->getFilesArray($arItems);
		}

		if ($useTilda) {
			$arItems = $this->getTildaFields($arItems, $fileArray);
		}

		$this->arResult["NAV_DATA"] = $this->setNavData();
		$this->arResult["ITEMS"] = $arItems;

		if ($showNavCHain) {
			$this->arResult["NAV_CHAIN"] = $this->getNavCHain();
		}

		$this->getSectionData();
	}

	protected function getSectionData()
	{
		if (count($this->arResult["ITEMS"]) > 0 && $this->arParams['SECTION_ID'] > 0) {
			$this->arResult['SECTION_ID'] = $this->arParams['SECTION_ID'];

			$section = [];
			foreach($this->arResult["ITEMS"] as $arItem) {
				if ($arItem['SECTION']['ID'] == $this->arParams['SECTION_ID']) {
					$section = $arItem['SECTION'];
					break;
				}
			}

			$this->arResult['SECTION'] = $section;
			if ($this->arResult['SECTION']['PAGE_URL']) {
				$section['SECTION_CODE'] = $this->arResult['SECTION'];
				$this->arResult['SECTION']['PAGE_URL'] = \CIBlock::ReplaceSectionUrl($this->arResult['SECTION']['PAGE_URL'], $section, false, 'E') ?: '';
			}

		}
	}

	protected function getFilesArray($arItems) : array
	{
		//get files array from items
		$fileArray = [];
		$fileIdArray = [];
		foreach($arItems as $arItem) {
			if ($arItem['PREVIEW_PICTURE']) $fileIdArray[] = $arItem['PREVIEW_PICTURE'];
			if ($arItem['DETAIL_PICTURE']) $fileIdArray[] = $arItem['DETAIL_PICTURE'];

			foreach ($arItem['PROPERTIES'] as $arProperty) {
				if ($arProperty['PROPERTY_TYPE'] == 'F' && !is_array($arProperty['VALUE']) && $arProperty['VALUE']) {
					$fileIdArray[] = $arProperty['VALUE'];
				}

				if ($arProperty['PROPERTY_TYPE'] == 'F' && is_array($arProperty['VALUE'])) {
					foreach($arProperty['VALUE'] as $fileId) {
						if ($fileId) $fileIdArray[] = $fileId;
					}
				}
			}
		}

		// Собираем массив с файлами одним запросом (превью и детальные картинки)
		if (!empty($fileIdArray)) {
			$uploadDir = \COption::GetOptionString("main", "upload_dir", "upload");
			$dbFiles = \CFile::GetList([], ['@ID' => implode($fileIdArray, ',')]);
			while($dbFile = $dbFiles->fetch()) {
				$dbFile['SRC'] = "/".$uploadDir."/".$dbFile["SUBDIR"]."/".$dbFile["FILE_NAME"];
				$fileArray[$dbFile['ID']] = $dbFile;
			}
		}

		return $fileArray;
	}

	protected function getTildaFields($arItems, $fileArray = [])
	{
		foreach($arItems as &$arItem) {
			$escapedArray = [];
			foreach ($arItem as $key => &$value) {
				if ($key == 'PROPERTIES') continue;
				$escapedArray['~'.$key] = $value;
				if (is_string($value)) $value = htmlentities($value);
			}

			$arItem = array_merge($arItem, $escapedArray);

			if ($arItem['PREVIEW_PICTURE']) {
				$arItem['~PREVIEW_PICTURE'] = $fileArray[$arItem['PREVIEW_PICTURE']];
			}

			if ($arItem['DETAIL_PICTURE']) {
				$arItem['~DETAIL_PICTURE'] = $fileArray[$arItem['DETAIL_PICTURE']];
			}

			foreach($arItem['PROPERTIES'] as &$property) {
				if ($property['VALUE'] && $property['PROPERTY_TYPE'] == 'F') {
					$property['~VALUE'] = $fileArray[$property['VALUE']];
				}
			}
		}

		return $arItems;
	}


	protected function setNavData() : array
	{
		$showNav = $this->showNav;
		$navParams = $this->navParams;

		if ($showNav && $navParams) {
			 return [
				"NavPageCount"  => $navParams->getPageCount(),
				"NavPageSize"   => $navParams->getPageSize(),
				"NavNum"        => $navParams->getId(),
				"NavPageNomer"  => $navParams->getCurrentPage(),
				"NavRecordCount" => $navParams->getRecordCount(),
			];
		}

		return [];
	}

	protected function getItems() : array
	{
		$filter = $this->filter;
		$selectItemsFields = $this->getSelect();
		$showNav = $this->showNav;
		$limit = $this->limit;
		$navParams = &$this->navParams;
		$arSort = $this->arSort ?: [];
		$useRandomSort = $this->useRandomSort;
		$showActive = $this->arParams["SHOW_ACTIVE"] === "Y";

		if ($showActive) {
			$filter->add("ACTIVE", "Y");
		}

		$arFilter = $filter->getFilter();
		$arRuntime = $filter->getRuntime();

		//Если для сортировки используется поле RAND
		if ($useRandomSort) {
			$arRuntime["RAND"] = [
				"data_type" => "integer",
				"expression" => ["RAND()", "ID"],
			];
		}

		$dbItems = \Bitrix\Iblock\ElementTable::getList([
			"filter" => $arFilter,
			"select" => $selectItemsFields,
			"offset" => $navParams ? $navParams->getOffset() : 0,
			"limit" => $navParams ? $navParams->getLimit() : $limit,
			"count_total" => $showNav && $navParams,
			"order" => $arSort,
			"group" => ['ID'],
			"runtime" => $arRuntime
		]);

		//Навигация
		if ($showNav && $navParams) {
			$navParams->setRecordCount($dbItems->getCount());
		}

		return $this->formatItems($dbItems);
	}

	protected function getElementsIds($arItems) : array
	{
		$elementIds = [];
		foreach($arItems as $arItem) {
			$elementIds[] = $arItem['ID'];
		}
		return $elementIds;
	}

	/**
	 * Получить список свойств для элементов
	 * @param $arItems
	 * @param $propertyCodes
	 * @return array
	 */
	protected function getProperties($arItems = [], $propertyCodes = []) : array
	{
		if (empty($arItems)) return [];

		$iblockId = $this->arParams['IBLOCK_ID'];
		$elementIds = $this->getElementsIds($arItems);

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
				"PROPERTY"
			],
			"filter" => $arFilter,
			"runtime" => [
				"PROPERTY" => [
					'data_type' => '\Bitrix\Iblock\PropertyTable',
					'reference' => [
						'=this.IBLOCK_PROPERTY_ID' => 'ref.ID',
					],
					'join_type' => "INNER"
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

			$property['TIMESTAMP_X'] = $property['TIMESTAMP_X']->format($this->defaultDateFormat);

			if ($property['MULTIPLE'] === 'Y') {
				$property['PROPERTY_VALUE_ID'] = [$property['PROPERTY_VALUE_ID']];
				$property['VALUE'] = [$property['VALUE']];
				$property['DESCRIPTION'] = [$property['DESCRIPTION']];
			}

			asort($property, SORT_NATURAL);

			if ($property['MULTIPLE'] === 'Y') {
				if (!$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']]) {
					$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']] = $property;
				}
				else {
					$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']]['PROPERTY_VALUE_ID'] = array_merge(
						$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']]['PROPERTY_VALUE_ID'],
						$property['PROPERTY_VALUE_ID']
					);
					$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']]['VALUE'] = array_merge(
						$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']]['VALUE'],
						$property['VALUE']
					);
					$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']]['DESCRIPTION'] = array_merge(
						$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']]['DESCRIPTION'],
						$property['DESCRIPTION']
					);
				}
			}
			else {
				$arProperties[$property['IBLOCK_ELEMENT_ID']][$property['CODE']] = $property;
			}
		}

		foreach($arItems as &$arItem) {
			$arItem['PROPERTIES'] = $arProperties[$arItem['ID']];
		}

		return $arItems;
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

	protected function getSelect()
	{
		$paramsSelect = $this->arParams["ADDITIONAL_SELECT"];

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
	 * @param $dbItems
	 * @return array
	 */
	protected function formatItems($dbItems) : array
	{
		$dateFormat = $this->arParams["DATE_FORMAT"] ?: $this->defaultDateFormat;
		$formatDate = $this->arParams["FORMAT_DATE"] !== "N";

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

			$urlTemplates = $this->setUrlTemplates($arItem);
			$arItem = array_merge($arItem, $urlTemplates);

			$arItem['PROPERTIES'] = [];

			$arItems[] = $arItem;
		}
		return $arItems;
	}

    /**
     * подготовка данных по кнопкам эрмитажа для режима правки
     */
    protected function initEditButtons()
    {
        if ($this->arParams["IBLOCK_ID"] <= 0) {
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
	protected function initNavData()
	{
		if ($this->limit && $this->showNav && $this->navigationName && $this->limit > 0) {
			$this->navParams = new PageNavigation($this->navigationName);
			$this->navParams->allowAllRecords(true)->setPageSize($this->limit)->initFromUri();
		}
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
                $this->arParams["IBLOCK_ID"],
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
