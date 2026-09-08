<?php

use App\Services\Parsers\PdfStatementParser;
use Illuminate\Http\UploadedFile;

/** @phpstan-ignore-next-line */
function callPrivate(PdfStatementParser $parser, string $method, array $args): mixed
{
    $ref = new ReflectionMethod(PdfStatementParser::class, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($parser, $args);
}

beforeEach(function () {
    $this->parser = new PdfStatementParser;
});

test('parseCandidateLine: дата DD.MM.YYYY + описание + сумма с запятой', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['05.03.2026  Покупка в магазине  1 500,00']);

    expect($result)->toBeArray()
        ->and($result['date'])->toBe('2026-03-05')
        ->and($result['description'])->toBe('Покупка в магазине')
        ->and($result['amount'])->toBe(1500.0)
        ->and($result['type'])->toBe('credit');
});

test('parseCandidateLine: дата DD.MM.YYYY + описание + отрицательная сумма', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['15.01.2026  Перевод СБП  -350,00']);

    expect($result)->toBeArray()
        ->and($result['date'])->toBe('2026-01-15')
        ->and($result['amount'])->toBe(-350.0)
        ->and($result['type'])->toBe('transfer');
});

test('parseCandidateLine: дата DD.MM.YYYY + сумма с точкой', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['10.06.2026  Зарплата  50 000.00']);

    expect($result)->toBeArray()
        ->and($result['date'])->toBe('2026-06-10')
        ->and($result['amount'])->toBe(50000.0)
        ->and($result['type'])->toBe('credit');
});

test('parseCandidateLine: дата YYYY-MM-DD + описание + сумма', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['2026-03-05  Оплата сервиса  999,99']);

    expect($result)->toBeArray()
        ->and($result['date'])->toBe('2026-03-05')
        ->and($result['amount'])->toBe(999.99)
        ->and($result['type'])->toBe('credit');
});

test('parseCandidateLine: перевод помечается как transfer', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['20.04.2026  Перевод Иванову  -5 000,00']);

    expect($result)->toBeArray()
        ->and($result['type'])->toBe('transfer');
});

test('parseCandidateLine: положительная сумма — credit', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['01.05.2026  Поступление  120 000,00']);

    expect($result)->toBeArray()
        ->and($result['type'])->toBe('credit')
        ->and($result['amount'])->toBe(120000.0);
});

test('isCandidateLine: возвращает true для строки с датой и суммой', function () {
    $result = callPrivate($this->parser, 'isCandidateLine', ['05.03.2026  Покупка  1 500,00']);

    expect($result)->toBeTrue();
});

test('isCandidateLine: возвращает false для заголовка', function () {
    $result = callPrivate($this->parser, 'isCandidateLine', ['Дата Описание Сумма']);

    expect($result)->toBeFalse();
});

test('isSkippableLine: пропускает итоговые строки', function () {
    expect(callPrivate($this->parser, 'isSkippableLine', ['Итого пополнений: 150 000,00']))->toBeTrue();
    expect(callPrivate($this->parser, 'isSkippableLine', ['Всего расходов: 50 000,00']))->toBeTrue();
    expect(callPrivate($this->parser, 'isSkippableLine', ['Остаток на счёте: 100 000,00']))->toBeTrue();
    expect(callPrivate($this->parser, 'isSkippableLine', ['05.03.2026  Покупка  1 500,00']))->toBeFalse();
});

test('extractAmountFromEnd: разбирает форматы сумм', function () {
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Покупка  1 500,00']))->toBe(1500.0);
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Покупка  -350,00']))->toBe(-350.0);
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Покупка  1234.56']))->toBe(1234.56);
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Покупка  50 000.00']))->toBe(50000.0);
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Покупка']))->toBeNull();
});

test('extractAmountFromEnd: кириллица перед суммой (pdf-склейка)', function () {
    // Реальный вывод smalot/pdfparser: дата склеена с описанием, нет пробелов
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Покупка в магазине Пятерочка 1 500,00']))->toBe(1500.0);
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Перевод СБП Иванову А.А. -3 500,00']))->toBe(-3500.0);
});

test('extractAmountFromEnd: год перед суммой (из описания)', function () {
    // "Зарплата за февраль 2026 85 000,00" — год склеен с суммой
    expect(callPrivate($this->parser, 'extractAmountFromEnd', ['Зарплата за февраль 2026 85 000,00']))->toBe(85000.0);
});

test('parseCandidateLine: формат pdf-таблицы без пробелов', function () {
    // Реальный формат: "05.03.2026Покупка в магазине Пятерочка 1 500,00"
    $result = callPrivate($this->parser, 'parseCandidateLine', ['05.03.2026Покупка в магазине Пятерочка 1 500,00']);

    expect($result)->toBeArray()
        ->and($result['date'])->toBe('2026-03-05')
        ->and($result['amount'])->toBe(1500.0)
        ->and($result['type'])->toBe('credit')
        ->and($result['description'])->toBe('Покупка в магазине Пятерочка');
});

test('parseCandidateLine: pdf-склейка + отрицательная сумма', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['05.03.2026Перевод СБП Иванову А.А. -3 500,00']);

    expect($result)->toBeArray()
        ->and($result['date'])->toBe('2026-03-05')
        ->and($result['amount'])->toBe(-3500.0)
        ->and($result['type'])->toBe('transfer');
});

test('parseCandidateLine: pdf-склейка + год в описании', function () {
    $result = callPrivate($this->parser, 'parseCandidateLine', ['06.03.2026Зарплата за февраль 2026 85 000,00']);

    expect($result)->toBeArray()
        ->and($result['date'])->toBe('2026-03-06')
        ->and($result['amount'])->toBe(85000.0)
        ->and($result['type'])->toBe('credit');
});

test('parse: пустой PDF возвращает data_rows=0', function () {
    // Генерируем минимальный PDF с пустым содержимым
    $tmpDir = sys_get_temp_dir();
    $tmpFile = $tmpDir.'/test-empty-'.uniqid().'.pdf';

    // Минимальный валидный PDF без текста
    file_put_contents($tmpFile, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>>>endobj\n4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\nxref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000266 00000 n \ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n345\n%%EOF");

    $uploaded = UploadedFile::fake()->createWithContent('empty.pdf', file_get_contents($tmpFile));
    @unlink($tmpFile);

    $result = $this->parser->parse($uploaded);

    expect($result['transactions'])->toBeEmpty()
        ->and($result['data_rows'])->toBe(0);
});
