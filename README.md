# Описание
Простой компонентов компонент списка и фильтрп для Bitrix.  
Делает меньше запросов, чем битриксовый news.list, отрабатывает быстрее.
В настоящее время не совместим с инфоблоки 2.0

# Composer
```json
{
  "require": {
    "tryhardy/components": "dev-master"
  },
  "repositories": [
    {
      "type": "git",
      "url": "https://github.com/tryhardy/components"
    }
  ],
  "extra": {
    "installer-paths": {
      "components/{$name}/": ["type:bitrix-d7-component"]
    }
  }
}
```
