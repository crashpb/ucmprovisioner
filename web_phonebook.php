<?php
declare(strict_types=1);

/** Network-restricted, read-only employee phonebook. No administrator bootstrap. */
require_once __DIR__ . '/lib/WebPhonebook.php';
require_once __DIR__ . '/lib/PhonebookWorkbook.php';
require_once __DIR__ . '/lib/WebPhonebookHttp.php';

\FreePBX\modules\Ucmprovisioner\WebPhonebookHttp::run(
    new \FreePBX\modules\Ucmprovisioner\WebPhonebook()
);
