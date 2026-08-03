# MikoPBX extension module template #

*Read this in other languages: [English](README.md), [Русский](README.ru.md).*

## Module description ##

We are working on the module developing guide here [https://docs.mikopbx.com](https://docs.mikopbx.com/mikopbx-development/)

## Ручной перенос истории звонков в 1С

Автономный экспортёр читает основную SQLite-базу CDR напрямую и не загружает классы модуля:

```bash
php bin/exportCdrTo1C.php \
  --from="2026-07-16 20:55:11" \
  --output="/tmp/cdr-history.xml"
```

Путь к базе по умолчанию — `/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db`. При необходимости его можно изменить параметром `--database=/path/to/cdr.db`. Граница `--from` включительная; если параметр не указан, используется `2026-07-16 20:55:11`.

Для загрузки установите в 1С файл `ЗагрузкаИсторииЗвонковMtsPBX_v2.epf`, откройте его форму и нажмите «Загрузить из XML». Существующая синхронизация с АТС и её `offset` сохраняются и не изменяются ручным импортом.

Перед массовой загрузкой сделайте резервную копию информационной базы 1С. Записи ищутся по `entire_id`, поэтому повторная загрузка обновляет уже найденные звонки, но резервная копия всё равно необходима.


### Questions ###
You are welcome to our telegram channel for developers [@mikopbx_dev](https://t.me/joinchat/AAPn5xSqZIpQnNnCAa3bBw)
