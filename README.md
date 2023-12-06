# Простой компонент списка
Использует для работы класс [\Tryhardy\BitrixFilter\ElementsFilter](https://github.com/tryhardy/bitrix-filter)  

# Composer
```json
{
  "require": {
    "tryhardy/bitrix-filter": "dev-master",
    "tryhardy/iblock.items": "dev-master"
  },
  "repositories": [
    {
      "type": "git",
      "url": "https://github.com/tryhardy/bitrix-filter.git"
    },
    {
      "type": "git",
      "url": "https://github.com/tryhardy/iblock.items"
    }
  ],
  "extra": {
    "installer-paths": {
      "components/{$name}/": ["type:bitrix-component"]
    }
  }
}
```