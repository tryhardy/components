# Простой компонент списка
Простой компонент списка для Bitrix.  
Делает меньше запросов, чем битриксовый news.list, отрабатывает быстрее.
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
