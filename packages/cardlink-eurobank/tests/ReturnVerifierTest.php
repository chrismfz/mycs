<?php

namespace Nixpal\CardlinkEurobank\Tests;

use Nixpal\CardlinkEurobank\ReturnVerifier;
use PHPUnit\Framework\TestCase;

final class ReturnVerifierTest extends TestCase
{
    private function verify(array $posted, $secret = Fixtures::SECRET, $mid = Fixtures::MID)
    {
        return ReturnVerifier::verify($posted, $secret, $mid);
    }

    public function test_browser_and_background_legs_of_a_card_capture_verify()
    {
        foreach (['browser', 'modirum'] as $leg) {
            $return = $this->verify(Fixtures::capture([], $leg));

            $this->assertTrue($return->verified, $leg);
            $this->assertSame('approved', $return->blestaStatus());
            $this->assertSame('42Inv26100912000007', $return->orderId());
            $this->assertSame('1.50', $return->amount());
            $this->assertSame('900000001', $return->txId());
            $this->assertNotContains('_charset_', $return->postedKeys);
        }
    }

    public function test_iris_capture_verifies()
    {
        $return = $this->verify(Fixtures::capture(['payMethod' => 'IRIS'], 'modirum'));

        $this->assertTrue($return->verified);
        $this->assertSame('approved', $return->blestaStatus());
        $this->assertSame('IRIS', $return->payMethod());
    }

    public function test_refused_and_cancelled_legs_without_pay_method_verify()
    {
        $refused = $this->verify(Fixtures::capture(['status' => 'REFUSED', 'payMethod' => null, 'paymentRef' => null]));
        $cancelled = $this->verify(Fixtures::capture(['status' => 'CANCELED', 'payMethod' => null, 'paymentRef' => null], 'modirum'));

        $this->assertTrue($refused->verified);
        $this->assertSame('declined', $refused->blestaStatus());
        $this->assertTrue($cancelled->verified);
        $this->assertNull($cancelled->blestaStatus());
    }

    public function test_status_mapping()
    {
        $map = ['AUTHORIZED' => 'pending', 'ERROR' => 'error', 'captured' => 'approved', 'SOMETHING' => null];
        foreach ($map as $status => $expected) {
            $this->assertSame($expected, $this->verify(Fixtures::capture(['status' => $status]))->blestaStatus(), $status);
        }
    }

    public function test_wrong_secret_is_refused_and_diagnosed()
    {
        $return = $this->verify(Fixtures::capture([], 'browser', 'other-secret'));

        $this->assertFalse($return->verified);
        $this->assertSame('digest_mismatch', $return->reason);
        $this->assertFalse($return->postedOrderMatches);
    }

    public function test_a_reordered_body_normalises_to_the_signed_order()
    {
        $signed = Fixtures::capture();
        $shuffled = ['status' => $signed['status'], 'txId' => $signed['txId']] + $signed;

        $this->assertTrue($this->verify($shuffled)->verified);
    }

    public function test_a_field_order_we_do_not_know_is_diagnosed_as_order_not_secret()
    {
        // vPOS signing in an order other than ours: the posted order verifies.
        $fields = ['mid' => Fixtures::MID, 'version' => '2', 'orderid' => '42Inv26100912000007', 'status' => 'CAPTURED',
            'orderAmount' => '1.50', 'currency' => 'EUR'];
        $return = $this->verify(Fixtures::sign($fields));

        $this->assertFalse($return->verified);
        $this->assertTrue($return->postedOrderMatches);
    }

    public function test_a_filler_field_is_refused()
    {
        $fields = Fixtures::capture();
        unset($fields['digest']);
        $return = $this->verify(Fixtures::sign(['zz' => '5'] + $fields));

        $this->assertFalse($return->verified);
        $this->assertSame('unknown_fields', $return->reason);
        $this->assertContains('zz', $return->postedKeys);
    }

    public function test_a_mid_boundary_shift_is_refused()
    {
        // Same signed bytes, re-split across mid|orderid.
        $genuine = Fixtures::capture(['orderid' => '5000042Inv261009120000']);
        $shifted = $genuine;
        $shifted['mid'] = 'MID1235';
        $shifted['orderid'] = '000042Inv261009120000';

        $this->assertTrue($this->verify($genuine)->verified);
        $return = $this->verify($shifted);
        $this->assertFalse($return->verified);
        $this->assertSame('mid_mismatch', $return->reason);
    }

    public function test_missing_mid_currency_or_digest_is_refused()
    {
        $this->assertSame('mid_mismatch', $this->verify(Fixtures::capture(['mid' => null]))->reason);
        $this->assertSame('currency_missing', $this->verify(Fixtures::capture(['currency' => null]))->reason);
        $noDigest = Fixtures::capture();
        unset($noDigest['digest']);
        $this->assertSame('no_digest', $this->verify($noDigest)->reason);
        $this->assertSame('no_secret', $this->verify(Fixtures::capture(), '')->reason);
        $this->assertSame('mid_mismatch', $this->verify(Fixtures::capture(), Fixtures::SECRET, '')->reason);
    }

    public function test_array_values_and_bad_order_ids_are_refused()
    {
        $posted = Fixtures::capture();
        $posted['message'] = ['x'];
        $this->assertSame('bad_value', $this->verify($posted)->reason);
        $this->assertSame('bad_orderid', $this->verify(Fixtures::capture(['orderid' => '42 Inv']))->reason);
    }

    public function test_numeric_currency_is_read_as_eur()
    {
        $this->assertSame('EUR', $this->verify(Fixtures::capture(['currency' => '978']))->currency());
    }

    public function test_summary_holds_no_digest()
    {
        $summary = $this->verify(Fixtures::capture())->summary();

        $this->assertArrayNotHasKey('digest', $summary);
        $this->assertNotContains('digest', $summary['posted_keys']);
        $this->assertSame('CAPTURED', $summary['status']);
    }
}
