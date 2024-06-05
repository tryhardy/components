<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)
{
	die();
}
/**
 * @var array $arCurrentValues
 * @var array $arComponentParameters
 * @var array $templateProperties
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!Loader::includeModule('iblock')) return;

Loc::loadMessages(__FILE__);

$templateProperties = $templateProperties ?? [];

$arComponentParameters = [];

$iblockExists = (!empty($arCurrentValues['IBLOCK_ID']) && (int)$arCurrentValues['IBLOCK_ID'] > 0);

$arTypesEx = CIBlockParameters::GetIBlockTypes();

$arIBlocks = [];
$iblockFilter = [
	'ACTIVE' => 'Y',
];
if (!empty($arCurrentValues['IBLOCK_TYPE'])) {
	$iblockFilter['TYPE'] = $arCurrentValues['IBLOCK_TYPE'];
}
if (isset($_REQUEST['site'])) {
	$iblockFilter['SITE_ID'] = $_REQUEST['site'];
}
$db_iblock = CIBlock::GetList(["SORT"=>"ASC"], $iblockFilter);
while($arRes = $db_iblock->Fetch()) {
	$arIBlocks[$arRes["ID"]] = "[" . $arRes["ID"] . "] " . $arRes["NAME"];
}

$arProperty_LNS = [];
$arProperty = [];
if ($iblockExists) {
	$rsProp = CIBlockProperty::GetList(
		[
			"SORT" => "ASC",
			"NAME" => "ASC",
		],
		[
			"ACTIVE" => "Y",
			"IBLOCK_ID" => $arCurrentValues["IBLOCK_ID"],
		]
	);
	while ($arr = $rsProp->Fetch()) {
		$arProperty[$arr["CODE"]] = "[" . $arr["CODE"] . "] " . $arr["NAME"];
		if (in_array($arr["PROPERTY_TYPE"], ["L", "N", "S"]))
		{
			$arProperty_LNS[$arr["CODE"]] = "[" . $arr["CODE"] . "] " . $arr["NAME"];
		}
	}
}

$arSorts = [
	"ASC"=>"ASC",
	"DESC"=>"DESC",
];
$arSortFields = [
	"ID"=>"ID",
	"NAME"=>"NAME",
	"ACTIVE_FROM"=>"ACTIVE_FROM",
	"SORT"=>"SORT",
	"TIMESTAMP_X"=>"TIMESTAMP_X",
];

$arComponentParameters = [
	"GROUPS" => [],
	"PARAMETERS" => [
		"CLASS" => [
			"PARENT" => "BASE",
			"NAME" => "Class",
			"TYPE" => "STRING",
		],
		"TITLE" => [
			"PARENT" => "BASE",
			"NAME" => "Заголовок",
			"TYPE" => "STRING",
		],
		"IBLOCK_TYPE" => [
			"PARENT" => "BASE",
			"NAME" => "Тип инфоблока",
			"TYPE" => "LIST",
			"VALUES" => $arTypesEx,
			"DEFAULT" => "news",
			"REFRESH" => "Y",
		],
		"IBLOCK_ID" => [
			"PARENT" => "BASE",
			"NAME" => "Инфоблок",
			"TYPE" => "LIST",
			"VALUES" => $arIBlocks,
			"DEFAULT" => '={$_REQUEST["ID"]}',
			"ADDITIONAL_VALUES" => "Y",
			"REFRESH" => "Y",
		],
		"COUNT_LIMIT" => [
			"PARENT" => "BASE",
			"NAME" => "Количество выводимых элементов",
			"TYPE" => "STRING",
			"DEFAULT" => "20",
		],
		"SORT_BY1" => [
			"PARENT" => "BASE",
			"NAME" => "Сортировка 1",
			"TYPE" => "LIST",
			"DEFAULT" => "ACTIVE_FROM",
			"VALUES" => $arSortFields,
			"ADDITIONAL_VALUES" => "Y",
		],
		"SORT_ORDER1" => [
			"PARENT" => "BASE",
			"NAME" => "Порядок сортировки 1",
			"TYPE" => "LIST",
			"DEFAULT" => "DESC",
			"VALUES" => $arSorts,
			"ADDITIONAL_VALUES" => "Y",
		],
		"SORT_BY2" => [
			"PARENT" => "BASE",
			"NAME" => "Сортировка 2",
			"TYPE" => "LIST",
			"DEFAULT" => "SORT",
			"VALUES" => $arSortFields,
			"ADDITIONAL_VALUES" => "Y",
		],
		"SORT_ORDER2" => [
			"PARENT" => "BASE",
			"NAME" => "Порядок сортировки 2",
			"TYPE" => "LIST",
			"DEFAULT" => "ASC",
			"VALUES" => $arSorts,
			"ADDITIONAL_VALUES" => "Y",
		],
		"FIELD_CODE" => CIBlockParameters::GetFieldCode("Поля", "DATA_SOURCE"),
		"ADDITIONAL_PROPERTIES" => [
			"PARENT" => "DATA_SOURCE",
			"NAME" => "Свойства",
			"TYPE" => "LIST",
			"MULTIPLE" => "Y",
			"VALUES" => $arProperty_LNS,
			"ADDITIONAL_VALUES" => "Y",
		],
		"SHOW_ACTIVE" => [
			"PARENT" => "DATA_SOURCE",
			"NAME" => "Выводить только активные элементы",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "Y",
		],
		"DETAIL_URL" => CIBlockParameters::GetPathTemplateParam(
			"DETAIL",
			"DETAIL_URL",
			"Ссылка на детальную страницу",
			"",
			"URL_TEMPLATES"
		),
		"DATE_FORMAT" => CIBlockParameters::GetDateFormat(GetMessage("T_IBLOCK_DESC_ACTIVE_DATE_FORMAT"), "ADDITIONAL_SETTINGS"),
		"SET_TITLE" => [],
		"SET_BROWSER_TITLE" => [
			"PARENT" => "ADDITIONAL_SETTINGS",
			"NAME" => "Устанавливать заголовок браузера",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "Y",
		],
		"SET_META_KEYWORDS" => [
			"PARENT" => "ADDITIONAL_SETTINGS",
			"NAME" => "Устанавливать метатег - ключевые слова",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "Y",
		],
		"SET_META_DESCRIPTION" => [
			"PARENT" => "ADDITIONAL_SETTINGS",
			"NAME" => "Устанавливать метатег - описание",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "Y",
		],
		"SET_CHAIN" => [
			"PARENT" => "ADDITIONAL_SETTINGS",
			"NAME" => "Устанавливать цепочку навигации",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "Y",
		],
		"SECTION_ID" => [
			"PARENT" => "ADDITIONAL_SETTINGS",
			"NAME" => "Раздел-родитель",
			"TYPE" => "STRING",
			"DEFAULT" => '',
		],
		"INCLUDE_SUBSECTIONS" => [
			"PARENT" => "ADDITIONAL_SETTINGS",
			"NAME" => "Выводить элементы подразделов",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "Y",
		],
		"CACHE_TIME"  =>  ["DEFAULT"=>36000000],
		"CACHE_FILTER" => [
			"PARENT" => "CACHE_SETTINGS",
			"NAME" => "Кэшировать при установленном фильтре",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "N",
		],
		"CACHE_GROUPS" => [
			"PARENT" => "CACHE_SETTINGS",
			"NAME" => "Учитывать права доступа",
			"TYPE" => "CHECKBOX",
			"DEFAULT" => "Y",
		],
		"DATE_FORMAT" => CIBlockParameters::GetDateFormat("Формат даты", "ADDITIONAL_SETTINGS")
	],
];
