<?php declare(strict_types=1);

namespace WeChatPay\Tests;

use function method_exists;
use function strlen;
use function abs;
use function strval;
use function preg_quote;
use function substr_count;
use function count;
use function ksort;

use WeChatPay\Formatter;
use PHPUnit\Framework\TestCase;

class FormatterTest extends TestCase
{
    private const LINE_FEED = "\n";

    /**
     * @return array<string,array{int,string}>
     */
    public function nonceRulesProvider(): array
    {
        return [
            'default $size=32'       => [32,  '/[a-zA-Z0-9]{32}/'],
            'half-default $size=16'  => [16,  '/[a-zA-Z0-9]{16}/'],
            'hundred $size=100'      => [100, '/[a-zA-Z0-9]{100}/'],
            'one $size=1'            => [1,   '/[a-zA-Z0-9]{1}/'],
            'zero $size=0'           => [0,   '/[a-zA-Z0-9]{2}/'],
            'negative $size=-1'      => [-1,  '/[a-zA-Z0-9]{3}/'],
            'negative $size=-16'     => [-16, '/[a-zA-Z0-9]{18}/'],
            'negative $size=-32'     => [-32, '/[a-zA-Z0-9]{34}/'],
        ];
    }

    /**
     * @dataProvider nonceRulesProvider
     */
    public function testNonce(int $size, string $pattern): void
    {
        $nonce = Formatter::nonce($size);

        self::assertIsString($nonce);

        self::assertTrue(strlen($nonce) === ($size > 0 ? $size : abs($size - 2)));

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $nonce);
        } else {
            self::assertRegExp($pattern, $nonce);
        }
    }

    public function testTimestamp(): void
    {
        $timestamp = Formatter::timestamp();
        $pattern = '/^1[0-9]{9}/';

        self::assertIsInt($timestamp);

        $timestamp = strval($timestamp);

        self::assertTrue(strlen($timestamp) === 10);

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $timestamp);
        } else {
            self::assertRegExp($pattern, $timestamp);
        }
    }

    public function testAuthorization(): void
    {
        $value = Formatter::authorization('1001', Formatter::nonce(), 'Cg==', (string) Formatter::timestamp(), 'mockmockmock');

        self::assertIsString($value);

        self::assertStringStartsWith('WECHATPAY2-SHA256-RSA2048 ', $value);
        self::assertStringEndsWith('"', $value);

        $pattern = '/^WECHATPAY2-SHA256-RSA2048 '
            . 'mchid="[0-9A-Za-z]{1,32}",'
            . 'serial_no="[0-9A-Za-z]{8,40}",'
            . 'timestamp="1[0-9]{9}",'
            . 'nonce_str="[0-9A-Za-z]{16,}",'
            . 'signature="[0-9A-Za-z\+\/]+={0,2}"$/';

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $value);
        } else {
            self::assertRegExp($pattern, $value);
        }
    }

    /**
     * @return array<string,array{string,string,string}>
     */
    public function requestPhrasesProvider(): array
    {
        return [
            'DELETE root(/)' => ['DELETE', '/', ''],
            'DELETE root(/) with query' => ['DELETE', '/?hello=wechatpay', ''],
            'GET root(/)' => ['GET', '/', ''],
            'GET root(/) with query' => ['GET', '/?hello=wechatpay', ''],
            'POST root(/) with body' => ['POST', '/', '{}'],
            'POST root(/) with body and query' => ['POST', '/?hello=wechatpay', '{}'],
            'PUT root(/) with body' => ['PUT', '/', '{}'],
            'PUT root(/) with body and query' => ['PUT', '/?hello=wechatpay', '{}'],
            'PATCH root(/) with body' => ['PATCH', '/', '{}'],
            'PATCH root(/) with body and query' => ['PATCH', '/?hello=wechatpay', '{}'],
        ];
    }

    /**
     * @dataProvider requestPhrasesProvider
     */
    public function testRequest(string $method, string $uri, string $body): void
    {
        $value = Formatter::request($method, $uri, (string) Formatter::timestamp(), Formatter::nonce(), $body);

        self::assertIsString($value);

        self::assertStringStartsWith($method, $value);
        self::assertStringEndsWith(static::LINE_FEED, $value);
        self::assertLessThanOrEqual(substr_count($value, static::LINE_FEED), 5);

        $pattern = '#^' . $method . static::LINE_FEED
            .  preg_quote($uri) . static::LINE_FEED
            . '1[0-9]{9}' . static::LINE_FEED
            . '[0-9A-Za-z]{32}' . static::LINE_FEED
            . preg_quote($body) . static::LINE_FEED
            . '$#';

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $value);
        } else {
            self::assertRegExp($pattern, $value);
        }
    }

    /**
     * @return array<string,array{string}>
     */
    public function responsePhrasesProvider(): array
    {
        return [
            'HTTP 200 STATUS with body' => ['{}'],
            'HTTP 200 STATUS with no body' => [''],
            'HTTP 202 STATUS with no body' => [''],
            'HTTP 204 STATUS with no body' => [''],
            'HTTP 301 STATUS with no body' => [''],
            'HTTP 301 STATUS with body' => ['<html></html>'],
            'HTTP 302 STATUS with no body' => [''],
            'HTTP 302 STATUS with body' => ['<html></html>'],
            'HTTP 307 STATUS with no body' => [''],
            'HTTP 307 STATUS with body' => ['<html></html>'],
            'HTTP 400 STATUS with body' => ['{}'],
            'HTTP 401 STATUS with body' => ['{}'],
            'HTTP 403 STATUS with body' => ['<html></html>'],
            'HTTP 404 STATUS with body' => ['<html></html>'],
            'HTTP 500 STATUS with body' => ['{}'],
            'HTTP 502 STATUS with body' => ['<html></html>'],
            'HTTP 503 STATUS with body' => ['<html></html>'],
        ];
    }

    /**
     * @dataProvider responsePhrasesProvider
     */
    public function testResponse(string $body): void
    {
        $value = Formatter::response((string) Formatter::timestamp(), Formatter::nonce(), $body);

        self::assertIsString($value);

        self::assertStringEndsWith(static::LINE_FEED, $value);
        self::assertLessThanOrEqual(substr_count($value, static::LINE_FEED), 3);

        $pattern = '#^1[0-9]{9}' . static::LINE_FEED
            . '[0-9A-Za-z]{32}' . static::LINE_FEED
            . preg_quote($body) . static::LINE_FEED
            . '$#';

        if (method_exists($this, 'assertMatchesRegularExpression')) {
            $this->assertMatchesRegularExpression($pattern, $value);
        } else {
            self::assertRegExp($pattern, $value);
        }
    }

    /**
     * @return array<string,array{string|int|bool|null|float}>
     */
    public function joinedByLineFeedPhrasesProvider(): array
    {
        return [
            'one argument' => [1],
            'two arguments' => [1, '2'],
            'more arguments' => [1, 2.0, '3', static::LINE_FEED, true, false, null, '4'],
        ];
    }

    /**
     * @param string $data
     * @dataProvider joinedByLineFeedPhrasesProvider
     */
    public function testJoinedByLineFeed(...$data): void
    {
        $value = Formatter::joinedByLineFeed(...$data);

        self::assertIsString($value);

        self::assertStringEndsWith(static::LINE_FEED, $value);

        self::assertLessThanOrEqual(substr_count($value, static::LINE_FEED), count($data));
    }

    public function testNoneArgumentPassedToJoinedByLineFeed(): void
    {
        $value = Formatter::joinedByLineFeed();

        self::assertIsString($value);

        self::assertStringNotContainsString(static::LINE_FEED, $value);

        self::assertTrue(strlen($value) == 0);
    }

    /**
     * @return array<string,array<array<string,string>>>
     */
    public function ksortByFlagNaturePhrasesProvider(): array
    {
        return [
            'normal' => [
                ['a' => '1', 'b' => '3', 'aa' => '2'],
                ['a' => '1', 'aa' => '2', 'b' => '3'],
            ],
            'key with numeric' => [
                ['rfc1' => '1', 'b' => '4', 'rfc822' => '2', 'rfc2086' => '3'],
                ['b' => '4', 'rfc1' => '1', 'rfc822' => '2', 'rfc2086' => '3'],
            ],
        ];
    }

    /**
     * @param array<string,string> $thing
     * @param array<string,string> $excepted
     * @dataProvider ksortByFlagNaturePhrasesProvider
     */
    public function testKsort(array $thing, array $excepted): void
    {
        self::assertEquals(Formatter::ksort($thing), $excepted);
    }

    /**
     * @return array<string,array<array<string,string>>>
     */
    public function nativeKsortPhrasesProvider(): array
    {
        return [
            'normal' => [
                ['a' => '1', 'b' => '3', 'aa' => '2'],
                ['a' => '1', 'aa' => '2', 'b' => '3'],
            ],
            'key with numeric' => [
                ['rfc1' => '1', 'b' => '4', 'rfc822' => '2', 'rfc2086' => '3'],
                ['b' => '4', 'rfc1' => '1', 'rfc2086' => '3', 'rfc822' => '2'],
            ],
        ];
    }

    /**
     * @param array<string,string> $thing
     * @param array<string,string> $excepted
     * @dataProvider nativeKsortPhrasesProvider
     */
    public function testNativeKsort(array $thing, array $excepted): void
    {
        self::assertTrue(ksort($thing));
        self::assertEquals($thing, $excepted);
    }

    /**
     * @return array<string,array{array<string,string|null>,string}>
     */
    public function queryStringLikePhrasesProvider(): array
    {
        return [
            'none specific chars' => [
                ['a' => '1', 'b' => '3', 'aa' => '2'],
                'a=1&b=3&aa=2',
            ],
            'has `sign` key' => [
                ['a' => '1', 'b' => '3', 'sign' => '2'],
                'a=1&b=3',
            ],
            'has `empty` value' => [
                ['a' => '1', 'b' => '3', 'c' => ''],
                'a=1&b=3',
            ],
            'has `null` value' => [
                ['a' => '1', 'b' => null, 'c' => '2'],
                'a=1&c=2',
            ],
            'mixed `sign` key, `empty` and `null` values' => [
                ['bob' => '1', 'alice' => null, 'tom' => '', 'sign' => 'mock'],
                'bob=1',
            ],
        ];
    }

    /**
     * @param array<string,string|null> $thing
     * @param string $excepted
     * @dataProvider queryStringLikePhrasesProvider
     */
    public function testQueryStringLike(array $thing, string $excepted): void
    {
        $value = Formatter::queryStringLike($thing);
        self::assertIsString($value);
        self::assertEquals($value, $excepted);
    }
}
