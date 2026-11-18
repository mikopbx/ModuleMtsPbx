# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Обзор проекта

Модуль интеграции MikoPBX с МТС Автосекретарь 2.0. Синхронизирует историю звонков (CDR) и записи разговоров из MTS API, принимает события входящих вызовов от MTS и транслирует их в 1С CRM через SOAP.

- **ID модуля:** `ModuleMtsPbx`
- **Пространство имён:** `Modules\ModuleMtsPbx\`
- **PHP:** 7.4.6+, **Фреймворк:** Phalcon 4.x/5.x
- **MikoPBX Core:** >= 2020.2.757

## Команды

Сборочной системы нет (без webpack/gulp/Makefile). PHP интерпретируется напрямую.

```bash
# Установка зависимостей
composer install

# Тест SOAP-подключения к 1С
php bin/test-soap.php

# Тесты (без PHPUnit, чистый PHP)
for t in tests/*.php; do php $t; done

# Сборка JS (src → public/assets/js)
docker run --rm -v "$PWD/..:/project/Extensions" ghcr.io/mikopbx/babel-compiler:latest \
  /project/Extensions/ModuleMtsPbx/public/assets/js/src/module-mts-pbx-index.js
```

Cron-задача `bin/synchCdr.php` запускается автоматически каждую минуту через `MtsPbxConf::createCronTasks()`.

## Архитектура

### Потоки данных

1. **Синхронизация CDR** (`bin/synchCdr.php` → `Lib/MtsCdrSync`, запуск по cron):
   для каждого включённого портала `MtsAccounts` (свой lock `/var/run/mts-sync-{id}.pid`, ошибка одного не останавливает остальные):
   JWT-токен → получение транков MTS → скользящие 10-дневные окна → пагинация CDR (1000/запрос) → скачивание MP3-записей → сохранение в `CallHistory` → публикация в Beanstalk → обновление offset портала

2. **Обработка событий** (POST `/pbxcore/api/mts-pbx/v1/event`):
   Basic Auth → маппинг событий MTS (CallAccepted/CallAnswered/EndCall → Calling/Connected/Finished, `Lib/MtsEventMapper`) → портал по `Domain` → получение списка пользователей из 1С (кеш 2 мин) → отправка событий в 1С через SOAP

3. **Выдача CDR** (GET `/pbxcore/mts-pbx/cdr?offset=X&limit=Y`):
   XML-ответ (`Lib/CdrXmlBuilder`, страница 5000, `limit` игнорируется) с заголовками пагинации `X-MIN-OFFSET`, `X-MAX-OFFSET`. Basic Auth (`inLogin`/`inPassword`) — если `cdrAuthEnabled = 1` (новые установки — включено, обновлённые — выключено)

### Ключевые классы и их базовые классы

| Класс | Extends | Роль |
|---|---|---|
| `Lib/MtsPbxMain` | `PbxExtensionBase` | Бизнес-логика, управление воркерами |
| `Lib/MtsPbxConf` | `ConfigClass` | Cron-задачи, REST-маршруты, fail2ban-фильтры |
| `Lib/WorkerMtsPbxMain` | `WorkerBase` | Слушатель Beanstalk-очереди |
| `Lib/WorkerMtsPbxAMI` | `WorkerBase` | Слушатель AMI-событий Asterisk |
| `Lib/RestAPI/GetController` | `BaseController` | REST-эндпоинты + SOAP-клиент 1С |
| `Setup/PbxExtensionSetup` | `PbxExtensionSetupBase` | Установка/удаление модуля |

### Модели (Phalcon ORM с аннотациями)

- **`ModuleMtsPbx`** → таблица `m_ModuleMtsPbx` — логин/пароль REST API, `cdrAuthEnabled`; поля `authApiKey`/`offset`/`gap` устарели (перенесены в `MtsAccounts` при установке, `accountsMigrated = 1`)
- **`MtsAccounts`** → таблица `m_ModuleMtsPbxAccounts` — порталы MTS: API-ключ, домен, gap, offset, enabled, `legacyIdFormat`
- **`CallHistory`** → таблица `mts_cdr` — CDR звонков MTS с индексами по UNIQUEID, linkedid, start; `mts_account_id`/`mts_call_id` (NULL у строк однопортальной версии)

### Внешние интеграции

- **MTS API** (`aa.mts.ru/api/ac20/`): REST, авторизация JWT-токеном (`authApiKey`)
- **1С CRM**: SOAP web-сервисы, namespace `http://wiki.miko.ru/uniphone:crmapi`, настройки из модуля `ModuleCTIClient`

## Соглашения

- UNIQUEID/linkedid звонков MTS: `fs-mts-<callId>` для портала с `legacyIdFormat = 1` (перенесён из однопортальной версии), `fs-mts-<accountId>-<callId>` для остальных (`Lib/MtsCallId`); так же именуется mp3, `call_id` событий — без префикса `fs-mts-`
- Каналы трансформируются в формат `PJSIP/mts_`
- Телефоны: 10-значные номера автодополняются префиксом `7` (российский формат)
- Часовой пояс: поле `gap` в настройках задаёт смещение в часах
- Логи: `{Directories::CORE_LOGS_DIR}/ModuleMtsPbx/{ClassName}.log` (на боевой PBX обычно `/storage/usbdisk1/mikopbx/log/ModuleMtsPbx/`), ротация на 40MB
- Кеш: Redis с префиксом `ModuleMtsPbx_`, TTL по умолчанию 86400с
- Совместимость Phalcon 4/5: через `MikoPBXVersion` — всегда использовать его для получения DI, валидаторов, текстовых утилит
- Локализация: `Messages/ru.php` и `Messages/en.php`, ключи `repModuleMtsPbx.*`
- UI: Semantic UI + jQuery, JS-исходник в `public/assets/js/src/`, скомпилированный в `public/assets/js/`
