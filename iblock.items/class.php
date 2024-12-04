<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Iblock\Component\Tools;
use Bitrix\Main;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Result;
use Bitrix\Main\UI\PageNavigation;

class SimpleIblockItemsComponent extends \CBitrixComponent
{
	/**
	 * @var int ID инфоблока
	 */
	protected int $iblockId;
    protected string $class = '';

	/**
	 * @var array|string[] кешируемые ключи arResult
	 */
    protected array $resultCacheKeys = [
	    "ID",
	    "IBLOCK_ID",
        "IBLOCK",
	    "TITLE",
	    "DESCRIPTION",
	    "SECTION_ID",
	    "SECTION",
	    "NAV_CHAIN",
	    "ITEMS",
	    "NAV_DATA",
	    "NAV_OBJECT",
	    "TEMPLATE_DATA"
    ];

	/** Массив с ID всех файлов, которые попадают в $arResult */
	protected array $fileIdsArray = [];

	/** Массив параметров для select по умолчанию */
    private array $defaultSelect = [
		"ID",
        "ACTIVE",
		"IBLOCK_ID",
		"NAME",
	    "CODE",
	    "XML_ID",
	    "IBLOCK_SECTION_ID",
	    "PREVIEW_TEXT",
	    "PREVIEW_PICTURE",
	    "ACTIVE_FROM",
    ];

	private array $iblockElementSectionSelect = [
		"IBLOCK_SECTION.ID",
		"IBLOCK_SECTION.CODE",
		"IBLOCK_SECTION.LEFT_MARGIN",
		"IBLOCK_SECTION.RIGHT_MARGIN",
	];

    /**
     * дополнительные параметры, от которых должен зависеть кэш
     * (filter, user id, user group, section id, etc)
     * @var array
     */
    protected array $cacheAddon = [];

	/**
	 * @var array|string[] модули, которые необходимо подключить для корректной работы компонента
	 */
    protected array $dependModules = [
		"iblock",
	    "highloadblock"
    ];

    /**
     * @var int количество элементов на странице
     */
	protected int $limit = 150;

	/**
	 * @var string Название объекта пагинации: '?pagination=page-1'
	 */
	protected string $navigationName = "pagination";

	/**
	 * @var bool выводить ли массив с пагинацией
	 */
	protected bool $showNav = false;

	/**
	 * @var PageNavigation|null Объект пагинации
	 */
	protected ?PageNavigation $navParams = null;

    protected array $filter = [];
    protected array $runtime = [];
    protected array $sort = [];
	/**
	 * @var array Порядок сортировки элементов
	 */
    protected array $arSort = [];

	/**
	 * @var bool true Если в SORT передается параметр RAND
	 */
	protected bool $useRandomSort = false;

	/**
	 * @var bool флаг использования кэша
	 */
    protected bool $fromCache = true;

	/**
	 * @var string формат даты по умолчанию
	 */
	protected string $defaultDateFormat = 'd.m.Y';

    /**
     * Подключаем языковые файлы
     */
    public function onIncludeComponentLang() : void
    {
        $this->includeComponentLang(basename(__FILE__));
        Loc::loadMessages(__FILE__);
    }

    /**
     * Собираем массив из входящих параметров $arParams
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
			//$params["CACHE_TIME"] = 0;
		}

        $params["IBLOCK_ID"] = (int) $params["IBLOCK_ID"];
        if ($params["IBLOCK_ID"] <= 0) {
            throw new Exception("Iblock ID not provided");
        }

		//Устанавливаем количество элементов на странице
        $params["COUNT_LIMIT"] = (int) $params["COUNT_LIMIT"];
        if ($params["COUNT_LIMIT"] <= 0) {
            $params["COUNT_LIMIT"] = $this->limit;
        }

	    //Массив с фильтром
        if (!$params["FILTER"] || !is_array($params["FILTER"])) {
	        $params["FILTER"] = [];
        }

		//Если в параметрах компонента переданы дополнительные поля для выборки
        if (isset($params["FIELD_CODE"]) && !empty($params["FIELD_CODE"])) {
            foreach ($params["FIELD_CODE"] as $i => $selectItem) {
				if (!$selectItem) {
					unset($params["FIELD_CODE"][$i]);
					continue;
				}

	            $params["FIELD_CODE"][$i] = trim($selectItem);
            }
        }

        //Доп. свойства
		if (isset($params['ADDITIONAL_PROPERTIES']) && !empty($params['ADDITIONAL_PROPERTIES'])) {
			foreach ($params["ADDITIONAL_PROPERTIES"] as $i => $selectItem) {
				if (!$selectItem) {
					unset($params["ADDITIONAL_PROPERTIES"][$i]);
					continue;
				}

				$params["ADDITIONAL_PROPERTIES"][$i] = trim($selectItem);
			}
		}

        //Параметры сортировки
		$sort = [];
        if ($params['SORT_BY1'] && $params['SORT_ORDER1']) {
            $sort[$params['SORT_BY1']] = $params['SORT_ORDER1'];
        }

        if ($params['SORT_BY2'] && $params['SORT_ORDER2']) {
            $sort[$params['SORT_BY2']] = $params['SORT_ORDER2'];
        }

		if ($params["SORT"] && is_array($params['SORT'])) {
			$sort += $params['SORT'];
		}

		//Основые параметры массива $arParams, необходимые для работы раздела
        $result = [
			"TITLE" => $params["TITLE"],
	        "DESCRIPTION" => $params["DESCRIPTION"],

	        "EDIT_MODE"             => $bDesignMode, //Включен режим правки
	        "IBLOCK_ID"             => intval($params["IBLOCK_ID"]), //ID ифноблока для фильтрации эл-тов
	        "SECTION_ID"            => intval($params["SECTION_ID"]), //ID раздела для фильтрации эл-тов
	        "INCLUDE_SUBSECTIONS"   => ($params["INCLUDE_SUBSECTIONS"] == "Y" ? "Y" : "N"), //Выводить элементы подразделов
	        "SECTION_GLOBAL_ACTIVE" => ($params["SECTION_GLOBAL_ACTIVE"] == "Y" ? "Y" : "N"), //Учитывать глобальную активность разделов
            "STRICT_SECTION_CHECK"  => $params["STRICT_SECTION_CHECK"] == "Y" ? "Y" : "N", //Строгая проверка раздела
	        "COUNT_LIMIT"           => $params["COUNT_LIMIT"], //Количество элементов на странице
	        "SORT"                  => $sort, //Сортировка
	        "SHOW_PROPERTIES"       => $params["SHOW_PROPERTIES"] == "Y" ? "Y" : "N", //Выводить свойства в списке
	        "SHOW_ACTIVE"           => ($params["SHOW_ACTIVE"] == "N" ? "N" : "Y"), //Выводить только активные элементы
	        "SET_DETAIL_URL"        => ($params["SET_DETAIL_URL"] == "N" ? "N" : "Y"), //Формировать URL-адрес

	        "DATE_FORMAT"           => $params["DATE_FORMAT"] ?: $this->defaultDateFormat, //Формат даты для вывода,
	        "FORMAT_DATE"           => ($params["FORMAT_DATE"] == "Y" ? "Y" : "N"), //Форматировать дату

	        "DETAIL_URL"            => trim($params["DETAIL_URL"]), //Шаблон детального URL
	        "SECTION_URL"           => trim($params["SECTION_URL"]), //Шаблон URL раздела
	        "LIST_URL"              => trim($params["LIST_URL"]),  //Шаблон URL списка

	        "SET_META"              => ($params["SET_META"] == "Y" ? "Y" : "N"), //Устанавливать мета-теги

	        "SET_CHAIN"             => ($params["SET_CHAIN"] == "Y" ? "Y" : "N"), //Устанавливать цепочку навигации

	        "CACHE_GROUPS"          => ($params["CACHE_GROUPS"] == "Y" ? "Y" : "N"), //Учитывать права доступа
	        "CACHE_TIME"            => $params["CACHE_TIME"], //Время кэширования
	        "CACHE_TYPE"            => $params["CACHE_TYPE"], //Тип кэширования
	        "CACHE_FILTER"          => ($params["CACHE_FILTER"] == "Y" ? "Y" : "N"), //Учитывать фильтр

	        "RETURN_ITEMS"          => ($params["RETURN_ITEMS"] == "Y" ? "Y" : "N"), // В качестве результата выполнения компонента возвращать $arResult

	        "FILE_404"              => trim($params["FILE_404"]), //Файл с сообщением, если не найдены элементы
	        "SHOW_404"              => ($params["SHOW_404"] == "Y" ? "Y" : "N"), //Выводить сообщение, если не найдены элементы
	        "SET_STATUS_404"        => ($params["SET_STATUS_404"] == "Y" ? "Y" : "N"), //Устанавливать статус 404, если не найдены элементы

	        //Блок пагинации
	        "SHOW_NAV"              => ($params["SHOW_NAV"] == "N" ? "N" : "Y"), //Выводить пагинацию
	        "PAGER_SHOW_ALWAYS"     => ($params["PAGER_SHOW_ALWAYS"] == "Y" ? "Y" : "N"), //Выводить всегда
	        "PAGER_TITLE"           => trim($params["PAGER_TITLE"]), //Заголовок блока пагинации
	        "PAGER_TEMPLATE"        => trim($params["PAGER_TEMPLATE"]), //Шаблон пагинации
	        "NAVIGATION_NAME"       => $params["NAVIGATION_NAME"] ?: ($this->getTemplateName() ?: 'nav') //Наименование объекта пагинации
        ];

        return $params + $result;
    }

	/**
	 * Устанавливает значения базовых свойств компонента
	 * @param array $params
	 * @return void
	 * @throws Exception
	 */
	protected function onPrepareClassProperties(array $params = []) : void
	{
		$this->iblockId = $params["IBLOCK_ID"];
		$this->fromCache = $params["CACHE_TIME"] > 0 && $params["CACHE_TYPE"] !== "N";
        $this->showNav = $this->arParams["SHOW_NAV"] === "Y";
        $this->limit = $this->arParams["COUNT_LIMIT"];
        $this->navigationName = $this->arParams["NAVIGATION_NAME"];
        $this->useRandomSort = in_array("RAND", array_values($params['SORT'])) || in_array("RAND", array_keys($params['SORT']));
	}

    /**
     * @param array $params
     * @return array
     */
	protected function onPrepareSort(array $params = [], bool $setRandomSort = false): array
    {
		$sort = $this->sort ?: [];
        $runtime = $this->runtime ?: [];

		if ($params["SORT_BY1"]) {
			$sort[$params["SORT_BY1"]] = $params["SORT_ORDER1"] ?: "ASC";
		}

		if ($params["SORT_BY2"]) {
			$sort[$params["SORT_BY2"]] = $params["SORT_ORDER2"] ?: "ASC";
		}

		foreach($params["SORT"] as $key => $value) {
            $sort[$key] = $value;
		}

        if ($setRandomSort) {
            $sort['RAND'] = 'ASC';
            $runtime['RAND'] = [
                "data_type" => "integer",
                "expression" => ["RAND()"],
            ];
        }

		return [$sort, $runtime];
	}

	/**
	 * Формирует объект фильтра для выборки по элементам
	 * @param int $sectionId
	 * @param array $section
	 * @return array
	 */
	protected function onPrepareFilter(int $sectionId = 0, array $section = []) : array
	{
		$params = $this->arParams;
		$filter = [];
        $runtime = [];
		$includeSubsections = $params["INCLUDE_SUBSECTIONS"] !== "N";

		if (is_array($params['FILTER'])) {
			$filter = $params['FILTER'];
		}

		$filter["IBLOCK_ID"] = $params["IBLOCK_ID"];

		//TODO add section filter with subsectios or not
		if ($sectionId > 0) {
            $filter['SECTIONS.IBLOCK_SECTION_ID'] = $sectionId;
            $filter["SECTIONS.IBLOCK_SECTION.GLOBAL_ACTIVE"] = "Y";
            $filter["SECTIONS.IBLOCK_SECTION.ACTIVE"] = "Y";
            $runtime['SECTIONS'] = [
                'data_type' => \Bitrix\Iblock\SectionElementTable::class,
                'reference' => [
                    '=this.ID' => 'ref.IBLOCK_ELEMENT_ID',
                ]
            ];

            //Если выбрано "включать подразделы"
            if ($includeSubsections) {
                unset($filter["SECTIONS.IBLOCK_SECTION_ID"]);

				if (empty($section)) {
					$section = \Bitrix\Iblock\SectionTable::getById($sectionId)->fetch();
				}

                if ($section) {
                    $filter['>=SECTIONS.IBLOCK_SECTION.LEFT_MARGIN'] = $section['LEFT_MARGIN'];
                    $filter['<=SECTIONS.IBLOCK_SECTION.RIGHT_MARGIN'] = $section['RIGHT_MARGIN'];
                }
            }
		}

		if ($this->arParams["SHOW_ACTIVE"] === "Y") {
			$filter["ACTIVE"] = "Y";
		}

		return [$filter, $runtime];
	}

    public function executeComponent()
    {
        try {
			//Проверяем, все ли модули подключены
	        $this->checkRequiredModules();
			//Устанавливаем свойства класса, необходимые для корректной работы компонента
			$this->onPrepareClassProperties($this->arParams);

            if (!$this->readDataFromCache()) {
				$this->getResult();

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
					$this->setResultCacheKeys([
						'TITLE',
						'DESCRIPTION',
						'SECTION_ID',
						'SECTION_CODE',
						'NAV_OBJECT',
						'NAV_DATA',
						'NAV_CHAIN',
						'SECTION'
					]);

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
	 * действия после выполения компонента, например установка заголовков из кеша
	 */
	protected function executeEpilog()
	{
		global $APPLICATION;
		$params = $this->arParams;
		$result = $this->arResult;

		$result['META'] = $this->setMeta();

		if ($params["SET_CHAIN"] == "Y" && !empty($result["NAV_CHAIN"])) {
			foreach ($result["NAV_CHAIN"] as $section) {
				$APPLICATION->AddChainItem(
					$section["NAME"],
					$section["SECTION_PAGE_URL"] ?? ""
				);
			}
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
                    "Module $module is not installed"
				);
			}
		}
	}

    /**
     * проверяет заполнение обязательных параметров
     * @throws Main\ArgumentNullException
     */
    protected function checkRequiredParams(array $params = [])
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

        //Кэшировать при установленном фильтре
        if ($params["CACHE_FILTER"] == "Y") {
            $request = Application::getInstance()->getContext()->getRequest();
            $systemParameters = $request->getSystemParameters();
            $requestValues = $request->getValues();

            //Исключаем из фильтра пустые значения и системные параметры
            foreach($requestValues as $key => $value) {
                if (!$value) unset($requestValues[$key]);
                if (in_array($key, $systemParameters)) unset($requestValues[$key]);
            }
	        $this->cacheAddon[] = $requestValues;
        }

        if ($params["CACHE_GROUPS"] == "Y" && is_object($user)) {
            $this->cacheAddon[] = $user->GetUserGroupArray();
        }

	    if (!empty($this->sort)) {
		    $this->cacheAddon[] = $this->sort;
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
		$params = $this->arParams;
        $this->class = \Bitrix\Iblock\Iblock::wakeUp($this->iblockId)->getEntityDataClass();
        if (!$this->class) throw new Exception('Iblock doesnt have API CODE');

		$showNavCHain = $this->arParams["SET_CHAIN"] === "Y";
		//Получаем ли мы пользовательские свойства (если да, делаются доп. запросы)
		$getProperties = $this->arParams["SHOW_PROPERTIES"] !== "N";
        //Коды свойств, которые нужно получать
		$propertyCodes = $this->arParams["ADDITIONAL_PROPERTIES"] ?: [];

		$this->arResult = [
			"TITLE" => $this->arParams["TITLE"] ?: null,
			"DESCRIPTION" => $this->arParams["DESCRIPTION"] ?: null,
			"ITEMS" => [],
			"SECTION_ID" => $this->arParams['SECTION_ID'] ?: null,
			"SECTION_CODE" => $this->arParams['SECTION_CODE'] ?: null,
		];

		$sectionId = 0;
		$section = [];
		if ($params['SECTION_ID'] || $params['SECTION_CODE']) {
			$section = $this->getSectionData();

			if (!$section) return false;
			$sectionId = $section['ID'];
			$this->arResult['SECTION'] = $section;
			$this->arResult['SECTION_ID'] = $section['ID'];
			$this->arResult['SECTION_CODE'] = $section['CODE'];
		}

		[$this->filter, $this->runtime] = $this->onPrepareFilter($sectionId, $section);
		[$this->sort, $this->runtime] = $this->onPrepareSort($params, $this->useRandomSort);

		// Получаем список элементов
		[$arItems, $navParams] = $this->getItems($this->filter, $this->runtime, $this->sort, $this->limit, $this->showNav);

		if ($getProperties) {
			$arItems = $this->getRecursiveProperties($arItems, $propertyCodes);
		}

        $fileArray = $this->getFilesArrayFromItems();

        if (!empty($fileArray)) {
            $arItems = $this->checkArrayAndFillFile($arItems, $fileArray);
        }

		$this->arResult["NAV_OBJECT"] = $navParams;
		$this->arResult["NAV_DATA"] = $this->setNavData($navParams);
		$this->arResult["ITEMS"] = $arItems;

		if ($showNavCHain) {
			$this->arResult["NAV_CHAIN"] = $this->getNavCHain();
		}
	}

	protected function checkArrayAndFillFile(array $array, array $fileArray) : array
	{
		foreach($array as $code => &$internal) {

			if (is_array($internal) && $code !== 'PROPERTIES') {
				$internal = $this->checkArrayAndFillFile($internal, $fileArray);
			}
			elseif (is_array($internal) && $code === 'PROPERTIES') {
				foreach($internal as $propCode => &$property) {
					if ($property['PROPERTY_TYPE'] == 'E' && is_array($property['VALUE'])) {
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
					elseif ($property['PROPERTY_TYPE'] == 'G') {
						if (is_array($property['VALUE']) && $property['VALUE']['PICTURE']) {
							$property['VALUE']['PICTURE'] = $fileArray[$property['VALUE']['PICTURE']];
						}
						elseif (is_array($property['VALUE'])) {
							foreach($property['VALUE'] as &$section) {
								if (!is_array($section)) break;
								if ($section['PICTURE']) {
									$section['PICTURE'] = $fileArray[$section['PICTURE']];
								}
							}
						}
					}
				}
			}
			else {
				if ($code === 'PREVIEW_PICTURE' && $internal > 0) {
					$internal = $fileArray[$internal] ?: $internal;
				}

				if ($code === 'DETAIL_PICTURE' && $internal > 0) {
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
				if ($property['PROPERTY_TYPE'] !== 'E' || empty($property['VALUE'])) continue;

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

			foreach($properties as &$property) {
				if ($property['PROPERTY_TYPE'] !== 'S' || empty($property['VALUE']) || $property['USER_TYPE'] !== 'ElementXmlID') continue;

				$value = $property['VALUE'];
				if (is_array($value)) {
					foreach($value as $i => $v) {
						$key = array_search($v, array_column($arPropsElements, 'XML_ID'));
						if ($key !== false) {
							$value[$i] = $arPropsElements[$key];
						}
					}
				} else {
					$key = array_search($value, array_column($arPropsElements, 'XML_ID'));
					if ($key !== false) {
						$value = $arPropsElements[$key];
					}
				}

				$property['VALUE'] = $value;
			}
		}
		return $arItems;
	}

	protected function getEPropertiesFromItems($arItems)
	{
		$propElementsIds = [];

		foreach($arItems as $arItem) {

			foreach($arItem['PROPERTIES'] as $property) {

				if (!$property['VALUE'] || $property['PROPERTY_TYPE'] !== 'E') continue;

				if (!is_array($property['VALUE'])) {
					$propElementsIds[] = $property['VALUE'];
					continue;
				}

				$getRecursive = false;
				foreach($property['VALUE'] as $value) {
					if (is_array($value)) {
						$getRecursive = true;
						break;
					}

					$propElementsIds[] = $value;
				}

				if ($getRecursive) {
					$propElementsIds = array_merge($propElementsIds, $this->getEPropertiesFromItems($property['VALUE']));
				}

			}
		}

		return array_unique($propElementsIds);
	}

	protected function getEXmlPropertiesFromItems($arItems)
	{
		$propElementsIds = [];

		foreach($arItems as $arItem) {

			foreach($arItem['PROPERTIES'] as $property) {

				if (!$property['VALUE'] || $property['PROPERTY_TYPE'] !== 'S' || $property['USER_TYPE'] !== 'ElementXmlID') continue;

				if (!is_array($property['VALUE'])) {
					$propElementsIds[] = $property['VALUE'];
					continue;
				}

				$getRecursive = false;
				foreach($property['VALUE'] as $value) {
					if (is_array($value)) {
						$getRecursive = true;
						break;
					}

					$propElementsIds[] = $value;
				}

				if ($getRecursive) {
					$propElementsIds = array_merge($propElementsIds, $this->getEXmlPropertiesFromItems($property['VALUE']));
				}

			}
		}

		return array_unique($propElementsIds);
	}

	protected int $curLevel = 0;

	/**
	 * Получить свойства элементов рекурсивно (до 2 уровня вложенности)
	 * @param array $arItems
	 * @param array $propertyCodes
	 * @return array
	 * @throws Main\LoaderException
	 */
	protected function getRecursiveProperties(array $arItems, array $propertyCodes) : array
	{
		$maxLevel = 2;
		$this->curLevel++;

		if (empty($arItems)) return $arItems;
		if ($this->curLevel > $maxLevel) return $arItems;

		$arPropertiesElements = [];

		//Получить свойства элементов и их значения с подзапросом для enums
		$arItems = $this->getProperties($arItems, $propertyCodes);
		$arItems = $this->getHLBlockPropertiesElements($arItems);
		$arItems = $this->getSectionPropertiesElements($arItems);
		$arItems = $this->getUserPropertiesElements($arItems);
		$arItems = $this->getPropertyValuesByUserType($arItems);

		$tmpEXmlPropItemsIDS = array_unique($this->getEXmlPropertiesFromItems($arItems));
		if (count($tmpEXmlPropItemsIDS) > 0) {
			$arPropertiesElements = $this->getIblockPropertiesElements($arPropertiesElements, $tmpEXmlPropItemsIDS, true);
		}

		//Получить элементы для свойств типа "Привязка к элементам"
		$tmpEPropItemsIDS = array_unique($this->getEPropertiesFromItems($arItems));
		if (count($tmpEPropItemsIDS) > 0) {
			$arPropertiesElements = $this->getIblockPropertiesElements($arPropertiesElements, $tmpEPropItemsIDS);
		}

		$arPropertiesElements = $this->getRecursiveProperties($arPropertiesElements, []);

		$arItems = $this->associateItemsWithArray($arItems, $arPropertiesElements);
		return $arItems;
	}

	protected function getPropertyValuesByUserType($arItems)
	{
		$propertyCustomTypes = [
			'UpFlexIblock'
		];

		foreach($arItems as &$arItem) {
			foreach($arItem['PROPERTIES'] as &$property) {
				if (!in_array($property['USER_TYPE'], $propertyCustomTypes)) continue;

				$arUserType = \CIBlockProperty::GetUserType($property['USER_TYPE']);

				if (!isset($arUserType["ConvertFromDB"])) continue;

				if(!array_key_exists("VALUE", $property)) continue;

				if (is_array($property["VALUE"])) {
					foreach($property["VALUE"] as &$propValue) {
						$value = ["VALUE"=>$propValue,"DESCRIPTION"=>""];
						$value = call_user_func_array($arUserType["ConvertFromDB"], [$property,$value]);
						$propValue = $value["VALUE"] ?? null;
					}
				}
				else {
					$value = ["VALUE" => $property["VALUE"],"DESCRIPTION"=>""];
					$value = call_user_func_array($arUserType["ConvertFromDB"], [$property,$value]);
					$property["VALUE"] = $value["VALUE"] ?? null;
				}
			}
		}

		return $arItems;
	}

    /**
     * Формируется список элементов без дополнительных свойств
     * @param array $filter
     * @param array $runtime
     * @param int $limit
     * @param bool $showNav
     * @param bool $debug
     * @return array
     */
	protected function getItems(array $filter, array $runtime, array $sort, int $limit, bool $showNav, bool $debug = false) : array
	{
		$arSort = $sort ?: ['ID' => 'DESC'];
		$arFilter = $filter;
		$arRuntime = $runtime;
        $selectItemsFields = $this->getSelect($arRuntime);

        $class = $arFilter['IBLOCK_ID'] == $this->iblockId ? $this->class : \Bitrix\Iblock\ElementTable::class;
		//Навигация
		$navParams = null;
		if ($showNav) {
			//Инициализируем объект навигации, если это требуется
			$navParams = $this->initNavData();
		}

		$dbItems = $class::getList([
			"filter" => $arFilter,
			"select" => $selectItemsFields,
			"offset" => $navParams ? $navParams->getOffset() : 0,
			"limit" => $navParams ? $navParams->getLimit() : $limit,
			"count_total" => $showNav && $navParams,
			"order" => $arSort,
			"runtime" => $arRuntime,
			"group" => ["ID"]
		]);

		if ($showNav && $navParams) {
			$navParams->setRecordCount($dbItems->getCount());
		}

		return [$this->formatItems($dbItems, $debug), $navParams];
	}

	/**
	 * Получить пользовательское свойство типа "Привязка к разделу"
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
			if ($section['PICTURE']) {
				$this->fileIdsArray[] = $section['PICTURE'];
			}
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

	/**
	 * Получить пользовательское свойство типа "Привязка к пользователю"
	 * @param array $arItems
	 * @param bool $debug
	 * @return array
	 * @throws ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	protected function getUserPropertiesElements(array $arItems = [], bool $debug = false) : array
	{
		$users = [];
		$arItemKeys = [];

		foreach ($arItems as $key => $arItem) {
			foreach ($arItem['PROPERTIES'] as $code => $property) {
				if ($property['USER_TYPE'] !== 'UserID' || empty($property['VALUE'])) {
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
	protected function getIblockPropertiesElements(array $arPropertiesElements = [], array $ids = [], bool $byXMLId = false) : array
	{
		$filter = [];
		if ($byXMLId) {
            $filter['XML_ID'] = $ids;
		}
		else {
            $filter['ID'] = $ids;
		}

		[$elements, $newNavParams] = $this->getItems($filter, [], [], false, false, true);
        echo '<pre>';
        print_r($arItems);
        echo '</pre>';
		foreach ($elements['PROPERTIES'] as $arProperty) {
			$this->setFileIdsFromProperty($arProperty);
		}

		return array_merge($elements, $arPropertiesElements);
	}

	/**
	 * Retrieve the elements of the iblock properties.
	 *
	 * @param array $arItems The array of items to retrieve properties from
	 * @param bool $debug Whether to enable debugging
	 * @return array The elements of the iblock properties
	 * @throws Exception
	 */
	protected function getIblockPropertiesXmlElements(array $ids = [], bool $debug = false) : array
	{
		$filter = [
            'XML_ID' => $ids,
            'ACTIVE' => 'Y'
        ];

		[$elements, $newNavParams] = $this->getItems($filter, [], [],false, false, true);

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

		if (empty($hlDbs)) {
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

	protected function getSectionData()
	{
		$params = $this->arParams;
        $getUFData = false;

		if (!$params['SECTION_ID'] && !$params['SECTION_CODE']) return [];

		$select = [
			'ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'ACTIVE', 'GLOBAL_ACTIVE',
			'SORT', 'NAME', 'CODE', 'PICTURE', 'LEFT_MARGIN', 'RIGHT_MARGIN',
			'DEPTH_LEVEL', 'DESCRIPTION', 'DESCRIPTION_TYPE', 'XML_ID',
		];

		if ($getUFData) {
			$entity = \Bitrix\Iblock\Model\Section::compileEntityByIblock($params['IBLOCK_ID']);
			$select[] = 'UF_*';
		}
		else {
			$entity = \Bitrix\Iblock\SectionTable::class;
		}

		$filter = ['IBLOCK_ID' => $params['IBLOCK_ID']];
		if ($params['SECTION_ID'] > 0) $filter['ID'] = $params['SECTION_ID'];
		if ($params['SECTION_CODE']) $filter['CODE'] = $params['SECTION_CODE'];

		return $entity::getList([
			'filter' => $filter,
			'select' => $select,
		])->fetch() ?: [];
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

	/**
	 * Получить список свойств для элементов
	 * @param int|array $iblockId
	 * @param array $arItems
	 * @param array $propertyCodes
	 * @return array
	 */
	protected function getProperties(array $arItems = [], array $propertyCodes = []) : array
	{
		if (empty($arItems)) return [];

		$elementIds = array_map(function($item) {
			return $item['ID'];
		}, $arItems);

		$arFilter = [
			"IBLOCK_ELEMENT_ID" => $elementIds,
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
				"FILTER_HINT" => "PROPERTY.HINT",
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
				],
//				"HINT" => [
//					'data_type' => '\Bitrix\Iblock\SectionPropertyTable',
//					'reference' => [
//						'=this.IBLOCK_PROPERTY_ID' => 'ref.PROPERTY_ID',
//					],
//					'join_type' => "LEFT"
//				]
			]
		])->fetchAll();

		$arProperties = [];
		foreach($propertiesValues as $propertyValue) {

			$property = $this->formatProperty($propertyValue);
			$multiple = $property['MULTIPLE'] === 'Y' ? true : false;
			$propertyValueId = $property['PROPERTY_VALUE_ID'];
			$code = $property['CODE'];
			$propertyIblockElementId = $property['IBLOCK_ELEMENT_ID'];

			$this->setFileIdsFromProperty($property);

			if ($multiple) {
				$key = $propertyValueId;
				if ($property['ENUM_XML_ID']) $key = $property['ENUM_XML_ID'];

				$property['VALUE'] = [$key => $property['VALUE']];
				$property['DESCRIPTION'] = [$key => $property['DESCRIPTION']];
				$property['PROPERTY_VALUE_ID'] = [$key => $propertyValueId];
			}

			if (!$arProperties[$propertyIblockElementId][$code]) {
				$arProperties[$propertyIblockElementId][$code] = $property;
			}

			if ($multiple && is_array($property['VALUE']) && !empty($property['VALUE'])) {
				foreach($property['VALUE'] as $key => $value) {
					$arProperties[$propertyIblockElementId][$code]['PROPERTY_VALUE_ID'][$key] = $key;
					$arProperties[$propertyIblockElementId][$code]['VALUE'][$key] = $value;
					$arProperties[$propertyIblockElementId][$code]['DESCRIPTION'][$key] = $property['DESCRIPTION'][$key];
				}
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
				$decoded = is_string($item) ? unserialize($item) : null;
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
		$sectionId = $this->arResult['SECTION_ID'];

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
	protected function getSelect(array $arRuntime) : array
	{
        $arSelect = [];
		$paramsSelect = $this->arParams["FIELD_CODE"];
		$setDetailUrl = $this->arParams["SET_DETAIL_URL"] === "Y";
        $showCounter = $this->arParams["SHOW_COUNTER"] == "Y";
        $strictSectionCheck = $this->arParams["STRICT_SECTION_CHECK"] == "Y";
		$arSelect += $this->defaultSelect;

        if ($arRuntime['SECTIONS'] && !$strictSectionCheck) {
            $arSelect['SECTIONS_'] = 'SECTIONS.IBLOCK_SECTION';
        }
        elseif ($arRuntime['SECTIONS'] && $strictSectionCheck) {
            $arSelect['SECTIONS_'] = 'IBLOCK_SECTION';
        }

        if ($setDetailUrl) {
            $arSelect = [
                "IBLOCK_ELEMENT_IBLOCK_ID" => "IBLOCK.ID",
                "IBLOCK_ELEMENT_IBLOCK_CODE" =>     "IBLOCK.CODE",
                "IBLOCK_ELEMENT_IBLOCK_LIST_PAGE_URL" =>     "IBLOCK.LIST_PAGE_URL",
                "IBLOCK_ELEMENT_IBLOCK_DETAIL_PAGE_URL" =>     "IBLOCK.DETAIL_PAGE_URL",
                "IBLOCK_ELEMENT_IBLOCK_SECTION_PAGE_URL" =>     "IBLOCK.SECTION_PAGE_URL",
            ] + $arSelect;
		}

		if ($showCounter) {
            $arSelect[] = "SHOW_COUNTER";
		}

		if (!$paramsSelect) {
			return $arSelect;
		}
		else {
			if (in_array("*", $paramsSelect)) return $arSelect + ["*"];
			else {
				foreach($paramsSelect as $value) $arSelect[] = $value;
			}

			return $arSelect;
		}
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
        $arSEO = [];

		if ($params["SET_META"] != "Y") return [];

		if ($result['SECTION_ID'] > 0) {
			$ipropValues = new \Bitrix\Iblock\InheritedProperty\SectionValues($iblockId, $result['SECTION_ID']);
			$arSEO = $ipropValues->getValues();

			if ($arSEO['SECTION_PAGE_TITLE']) {
				$APPLICATION->SetTitle($arSEO['SECTION_PAGE_TITLE']);
			}
			elseif ($result['SECTION']) {
				$APPLICATION->SetTitle($result['SECTION']['NAME']);
			}

			if ($arSEO['SECTION_META_TITLE'] != false) {
				$APPLICATION->SetPageProperty("title", $arSEO['SECTION_META_TITLE']);
			}
			elseif ($result['SECTION']) {
				$APPLICATION->SetPageProperty("title", $result['SECTION']['NAME']);
			}

			if ($arSEO['SECTION_META_KEYWORDS'] != false) {
				$APPLICATION->SetPageProperty("keywords", $arSEO['SECTION_META_KEYWORDS']);
			}

			if ($arSEO['SECTION_META_DESCRIPTION'] != false) {
				$APPLICATION->SetPageProperty("description", $arSEO['SECTION_META_DESCRIPTION']);
			}
		}

        if ($this->arResult['NAV_OBJECT']) {
            $paginationId = $this->arResult['NAV_OBJECT']->getId();
            $requestValues = $this->request->getValues();
            $inRequest = isset($requestValues[$paginationId]);

            if ($inRequest) {
                $currentPage = $this->arResult['NAV_OBJECT']->getCurrentPage();
                $titlePage = \Bitrix\Main\Localization\Loc::getMessage('PAGE_TITLE', ['#NUMBER#' => $currentPage]);
                $description = $APPLICATION->getPageProperty('description');
                $title = $APPLICATION->getPageProperty('title');

                if ($title && !str_contains($title, $titlePage)) {
                    $title = trim($title) . 'news.list ' . $titlePage;
                    $APPLICATION->setPageProperty('title', $title);
                }

                if ($description && !str_contains($description, $titlePage)) {
                    $description = trim($description) . 'news.list ' . $titlePage;
                    $APPLICATION->setPageProperty('description', $description);
                }

                //$APPLICATION->setPageProperty('robots', 'noindex, follow');
            }
        }

		return $arSEO;
	}

	/**
	 * @param array $arItems
	 * @return Generator
	 */
	protected function HLDataGenerator(array $arItems = []) : Generator
	{
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

	protected function formatProperty(array $propertyValue)
	{
		$property = [];
		$iblockPropertyCode = 'IBLOCK_ELEMENT_PROPERTY_PROPERTY_';

		foreach($propertyValue as $fieldCode => $fieldValue) {
			if (stripos($fieldCode, $iblockPropertyCode) !== false) {
				$fieldCode = str_ireplace($iblockPropertyCode, '', $fieldCode);
			}
			$property[$fieldCode] = $fieldValue;
		}

		unset($property['VALUE_ENUM']);

		if ($property['ENUM_VALUE']) {
			$property['VALUE'] = $property['ENUM_VALUE'];
			unset($property['ENUM_VALUE']);
		}

		if ($property['USER_TYPE_SETTINGS']) {
			$property['USER_TYPE_SETTINGS'] = unserialize($property['USER_TYPE_SETTINGS']);
		}

		$property['VALUE'] = $this->getUnserializedValue($property['VALUE']);

		asort($property, SORT_NATURAL);

		return $property;
	}

	/**
	 * @param Result $dbItems
	 * @param bool $debug
	 * @return array
	 */
	protected function formatItems(Result $dbItems, bool $debug = false) : array
	{
		$formatDate = true; //Форматировать ли дату
		$dateFormat = $this->arParams["DATE_FORMAT"] ?: $this->defaultDateFormat;
		$setDetailUrl = $this->arParams["SET_DETAIL_URL"] === "Y";

		$arItems = [];
		foreach($dbItems as $dbItem) {
			$arItem = [];

			$arItem['ID'] = $dbItem['ID'];
			$arItem['IBLOCK_ID'] = $dbItem['IBLOCK_ID'];
			$arItem['IBLOCK_SECTION_ID'] = $dbItem['IBLOCK_SECTION_ID'];
			$arItem['DETAIL_PAGE_URL'] = '';

            $iblockCode = 'IBLOCK_ELEMENT_IBLOCK_';
            $iblockSectionCode = 'SECTIONS_';

			foreach($dbItem as $fieldCode => $fieldValue) {
                //Отделяем поля раздела
                if ($fieldCode && stripos($fieldCode, $iblockSectionCode) !== false) {
                    if (!$arItem['SECTION']) $arItem['SECTION'] = [];
                    $fieldCodeName = str_ireplace($iblockSectionCode, '', $fieldCode);
                    $arItem['SECTION'][$fieldCodeName] = $fieldValue;
                    continue;
                }

                //Отделяем поля инфоблока
                if (stripos($fieldCode, $iblockCode) !== false) {
                    $fieldCodeName = str_ireplace($iblockCode, '', $fieldCode);
                    $arItem['IBLOCK'][$fieldCodeName] = $fieldValue;
                    continue;
                }

                //Форматируем дату начала активности
				if ($fieldCode === 'ACTIVE_FROM' && $fieldValue && $formatDate) {
					$arItem['DATE_ACTIVE_FROM'] = \CIBlockFormatProperties::DateFormat($dateFormat, $fieldValue->getTimestamp());
					$arItem['TIMESTAMP_ACTIVE_FROM'] = $fieldValue->getTimestamp();
					$arItem['ACTIVE_FROM_UNIX'] = $fieldValue->getTimestamp();
                    continue;
				}

                //Форматируем дату окончания активности
                if ($fieldCode === 'ACTIVE_TO' && $fieldValue && $formatDate) {
					$arItem['DATE_ACTIVE_TO'] = \CIBlockFormatProperties::DateFormat($dateFormat, $fieldValue->getTimestamp());
					$arItem['TIMESTAMP_ACTIVE_TO'] = $fieldValue->getTimestamp();
					$arItem['ACTIVE_TO_UNIX'] = $fieldValue->getTimestamp();
                    continue;
				}

                $arItem[$fieldCode] = $fieldValue;
			}

			if ($setDetailUrl) {
				$urlTemplates = $this->setUrlTemplates($arItem);
				$arItem = array_merge($arItem, $urlTemplates);
			}

			$arItem['PROPERTIES'] = [];

			if ($arItem['PREVIEW_PICTURE']) $this->fileIdsArray[] = $arItem['PREVIEW_PICTURE'];
			if ($arItem['DETAIL_PICTURE']) $this->fileIdsArray[] = $arItem['DETAIL_PICTURE'];
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

        if (!$this->arParams["EDIT_MODE"]) {
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

        if (!$params["EDIT_MODE"]) {
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
