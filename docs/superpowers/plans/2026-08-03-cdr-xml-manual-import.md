# CDR XML Manual Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить автономную выгрузку основной SQLite-истории MikoPBX в XML и выпустить отдельную EPF v2 с ручным импортом этого XML.

**Architecture:** Один самостоятельный PHP CLI-файл выполняет валидацию аргументов, обнаружение совместимой таблицы CDR, потоковую группировку по идентификатору звонка и атомарную запись XML. EPF v2 хранится вместе с декомпилированными исходниками: новая клиентская команда выбирает XML, а существующая серверная логика разбора и записи повторно используется для импорта.

**Tech Stack:** PHP 7.4+, PDO SQLite, XMLWriter или потоковая запись XML, 1С BSL, `v8unpack 1.2.6`, собственный PHP test runner без Composer-зависимостей.

## Global Constraints

- Экспортёр не подключает `Globals.php`, Composer autoload или классы ModuleMtsPbx.
- База по умолчанию: `/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db`.
- Дата по умолчанию и включительная граница: `2026-07-16 20:55:11`.
- Исходный `ЗагрузкаИсторииЗвонковMtsPBX_v1.epf` не изменяется.
- Итоговый EPF имеет отдельное имя с версией `v2`.
- Код должен выполняться на PHP 7.4.6+.
- Проверка BSL на этом Mac структурная; запуск в 1С требует целевой базы.

---

### Task 1: CLI-контракт и чтение совместимой SQLite-схемы

**Files:**
- Create: `tests/exportCdrTo1CTest.php`
- Create: `bin/exportCdrTo1C.php`

**Interfaces:**
- Consumes: CLI `php bin/exportCdrTo1C.php [--database=PATH] [--from=DATE] --output=PATH`.
- Produces: код завершения `0` и итоговая статистика либо ненулевой код и сообщение в STDERR.

- [ ] **Step 1: Write the failing CLI tests**

Создать тестовый runner, который запускает реальный CLI через `PHP_BINARY`, создаёт временную SQLite-базу PDO и проверяет: `--help` работает без базы; отсутствие `--output`, неверная дата, отсутствующая база и таблица без обязательных полей завершаются ошибкой; дата по умолчанию применяется включительно.

- [ ] **Step 2: Run tests to verify RED**

Run: `php tests/exportCdrTo1CTest.php`

Expected: FAIL, потому что `bin/exportCdrTo1C.php` отсутствует.

- [ ] **Step 3: Implement the minimal CLI and schema discovery**

В `bin/exportCdrTo1C.php` реализовать разбор long options без сторонних библиотек, строгий `DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', ...)`, проверку `PDO::getAvailableDrivers()`, открытие SQLite только для чтения и обнаружение первой таблицы из `cdr`, `cdr_general`, `miko_cdr`, содержащей поля `start`, `linkedid`, `UNIQUEID`, `src_num`, `dst_num`. Имена таблиц и колонок брать только из проверенных метаданных и экранировать двойными кавычками.

- [ ] **Step 4: Run tests to verify GREEN**

Run: `php tests/exportCdrTo1CTest.php`

Expected: PASS для CLI-контракта и схемы.

- [ ] **Step 5: Commit**

```bash
git add bin/exportCdrTo1C.php tests/exportCdrTo1CTest.php
git commit -m "feat: add standalone CDR export CLI"
```

### Task 2: Потоковый XML и группировка плеч звонка

**Files:**
- Modify: `tests/exportCdrTo1CTest.php`
- Modify: `bin/exportCdrTo1C.php`

**Interfaces:**
- Consumes: строки CDR, отсортированные по вычисленному идентификатору, `start`, `id`.
- Produces: UTF-8 XML `history/history_record/details/from/to`, атомарно записанный в `--output`.

- [ ] **Step 1: Write failing behavior tests**

Добавить fixture с двумя плечами одного `linkedid`, отдельной строкой с пустым `linkedid`, XML-символами в номерах, `ANSWERED`/`NO ANSWER`, пустыми датами и строкой до границы. Проверить через `DOMDocument`: одна группа на `linkedid`, два `details`, fallback на `UNIQUEID`, точные статусы, RFC 3339/пустые даты, экранированные значения и отсутствие старой строки.

Отдельный тест создаёт существующий output, затем вызывает экспорт с ошибочной схемой и проверяет, что старое содержимое сохранилось.

- [ ] **Step 2: Run tests to verify RED**

Run: `php tests/exportCdrTo1CTest.php`

Expected: FAIL на отсутствии XML-группировки и атомарной записи.

- [ ] **Step 3: Implement minimal streaming export**

Добавить `SELECT` с `COALESCE(NULLIF(linkedid, ''), UNIQUEID)` и стабильной сортировкой. Писать XML через `XMLWriter` в уникальный временный файл рядом с output; открыть/закрыть `history_record` при смене идентификатора; атрибуты получать из строки без накопления всего результата в памяти. `answer` и `endtime` преобразовывать только при непустом корректном значении; после успешного `flush` выполнять `rename`, при исключении удалять только временный файл.

- [ ] **Step 4: Run tests to verify GREEN**

Run: `php tests/exportCdrTo1CTest.php`

Expected: все тесты PASS, вывод содержит количества строк, звонков и пропусков.

- [ ] **Step 5: Verify PHP compatibility and commit**

Run: `php -l bin/exportCdrTo1C.php && php -l tests/exportCdrTo1CTest.php && php tests/exportCdrTo1CTest.php`

```bash
git add bin/exportCdrTo1C.php tests/exportCdrTo1CTest.php
git commit -m "feat: export grouped call history XML"
```

### Task 3: Исходники EPF v2 и ручной выбор XML

**Files:**
- Create: `onec/ЗагрузкаИсторииЗвонковMtsPBX_v2/ExternalDataProcessor.json`
- Create: `onec/ЗагрузкаИсторииЗвонковMtsPBX_v2/ExternalDataProcessor.obj.bsl`
- Create: `onec/ЗагрузкаИсторииЗвонковMtsPBX_v2/Form/ОсновнаяФорма/Form.json`
- Create: `onec/ЗагрузкаИсторииЗвонковMtsPBX_v2/Form/ОсновнаяФорма/Form.elem.json`
- Create: `onec/ЗагрузкаИсторииЗвонковMtsPBX_v2/Form/ОсновнаяФорма/Form.id.json`
- Modify: `tests/exportCdrTo1CTest.php`

**Interfaces:**
- Consumes: выбранный пользователем XML в формате Task 2.
- Produces: форма с отдельной командой `ЗагрузитьИзXML`; серверная функция импортирует содержимое через `ОбработатьЗаписиИстории` и `ПроверитьОбработкуПропущенныхЗвонков`, не меняя `offset`.

- [ ] **Step 1: Decompile the existing EPF as the v2 source baseline**

Run: `/Users/apor/Library/Python/3.9/bin/v8unpack -E ЗагрузкаИсторииЗвонковMtsPBX_v1.epf onec/ЗагрузкаИсторииЗвонковMtsPBX_v2`

Сразу проверить `git diff --no-index` между временной повторной декомпиляцией исходного EPF и созданной базой; различий быть не должно.

- [ ] **Step 2: Write the failing EPF source contract test**

Расширить runner проверкой декомпилированной модели формы: команда `ЗагрузитьИзXML` присутствует среди команд и элементов; клиентский обработчик использует стандартный диалог/помещение файла; серверный код вызывает общий импорт и отменяет транзакцию при исключении. Проверка должна читать структурированный `Form.elem.json`, а BSL-контракт проверять по именам экспортных процедур и вызовам, необходимым для исполнения.

- [ ] **Step 3: Run tests to verify RED**

Run: `php tests/exportCdrTo1CTest.php`

Expected: FAIL, команда ручного импорта отсутствует.

- [ ] **Step 4: Implement the manual import command**

В `Form.elem.json` добавить команду `ЗагрузитьИзXML` с уникальным UUID и кнопку командной панели. В модуле формы добавить клиентский выбор единственного XML-файла, помещение во временное хранилище и вызов серверной процедуры. Серверная процедура получает строку UTF-8, удаляет адрес хранилища в блоке завершения, начинает транзакцию, вызывает общий импорт, фиксирует транзакцию только при успехе и отменяет её при исключении с записью в журнал регистрации.

В `ExternalDataProcessor.obj.bsl` выделить минимальную общую функцию импорта XML, чтобы сетевой и ручной пути использовали одинаковые `ОбработатьЗаписиИстории` и `ПроверитьОбработкуПропущенныхЗвонков` без копирования бизнес-логики. Обновить версию регистрации до `2.0`.

- [ ] **Step 5: Run tests to verify GREEN**

Run: `php tests/exportCdrTo1CTest.php`

Expected: PASS, исходный EPF по-прежнему имеет исходный SHA-256.

- [ ] **Step 6: Commit sources**

```bash
git add onec/ЗагрузкаИсторииЗвонковMtsPBX_v2 tests/exportCdrTo1CTest.php
git commit -m "feat: add manual XML import to 1C processing"
```

### Task 4: Сборка EPF v2 и сквозная верификация

**Files:**
- Create: `ЗагрузкаИсторииЗвонковMtsPBX_v2.epf`
- Modify: `README.md`

**Interfaces:**
- Consumes: исходники Task 3 и XML Task 2.
- Produces: готовый EPF v2 и документированная команда выгрузки.

- [ ] **Step 1: Build the new EPF**

Run: `/Users/apor/Library/Python/3.9/bin/v8unpack -B onec/ЗагрузкаИсторииЗвонковMtsPBX_v2 ЗагрузкаИсторииЗвонковMtsPBX_v2.epf`

- [ ] **Step 2: Verify the built artifact by decompilation**

Разобрать новый EPF во временный каталог, повторно запустить контрактные тесты против полученных исходников и убедиться, что SHA-256 исходного v1 не изменился. Проверить `file` и ненулевой размер нового EPF.

- [ ] **Step 3: Document operational usage**

В `README.md` добавить короткий раздел с командой экспорта, значениями параметров по умолчанию, примером ручной загрузки v2 и предупреждением сначала сделать резервную копию базы 1С перед массовым импортом.

- [ ] **Step 4: Run final verification**

Run: `php -l bin/exportCdrTo1C.php && php tests/exportCdrTo1CTest.php && git diff --check`

Expected: lint PASS, tests PASS, no whitespace errors. Затем декомпилировать `ЗагрузкаИсторииЗвонковMtsPBX_v2.epf` и подтвердить наличие команды `ЗагрузитьИзXML` в собранном артефакте.

- [ ] **Step 5: Commit artifact and documentation**

```bash
git add ЗагрузкаИсторииЗвонковMtsPBX_v2.epf README.md
git commit -m "docs: ship CDR XML import workflow"
```
