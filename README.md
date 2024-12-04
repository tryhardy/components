# Описание
Простой набор компонентов (компонент списка и фильтра) для проектов на Bitrix.  
Компоненты делают меньше запросов, чем битриксовый news.list, отрабатывают быстрее.
В настоящее время компоненты не совместимы с "инфоблоки 2.0"

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
