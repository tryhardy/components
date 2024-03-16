# Простой компонент списка
Простой компонент списка для Bitrix.  
Делает меньше запросов, чем битриксовый news.list, отрабатывает быстрее.  
Заточен под twig на uplab-овских проектах

Использует для работы класс [\Tryhardy\BitrixFilter\ElementsFilter](https://github.com/tryhardy/bitrix-filter)  
В настоящее время не совместим с инфоблоки 2.0

# Composer
```json
{
  "require": {
    "tryhardy/bitrix.filter": "dev-master",
    "tryhardy/iblock.items": "dev-master"
  },
  "repositories": [
    {
      "type": "git",
      "url": "https://github.com/tryhardy/bitrix.filter"
    },
    {
      "type": "git",
      "url": "https://github.com/tryhardy/iblock.items"
    }
  ],
  "extra": {
    "installer-paths": {
      "components/{$name}/": ["type:bitrix-d7-component"]
    }
  }
}
```
