<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */

use App\Model\Entity\Customer;
use App\Model\Entity\OrderDetail;
use App\Test\TestCase\AppCakeTestCase;
use App\Test\TestCase\Traits\AppIntegrationTestTrait;
use App\Test\TestCase\Traits\LoginTrait;
use App\Test\TestCase\Traits\PrepareAndTestInvoiceDataTrait;
use Cake\Core\Configure;
use Cake\TestSuite\EmailTrait;

/**
 * FCS_BILL_ORDERS_WITHOUT_INVOICES: the "send invoices to customers" cronjob only marks past orders as billed.
 */
class BillOrdersWithoutInvoicesCommandTest extends AppCakeTestCase
{
    use AppIntegrationTestTrait;
    use EmailTrait;
    use LoginTrait;
    use PrepareAndTestInvoiceDataTrait;

    private const RUN_DAY = '2018-02-02 10:20:30';

    public function setUp(): void
    {
        parent::setUp();
        $this->prepareSendingInvoices();
        $this->changeConfiguration('FCS_SEND_INVOICES_TO_CUSTOMERS', 1);
        $this->changeConfiguration('FCS_BILL_ORDERS_WITHOUT_INVOICES', 1);
        $this->loginAsSuperadmin();
        $this->prepareOrdersAndPaymentsForInvoice((int) Configure::read('test.superadminId'));
        $this->ids = array_keys($this->states());
        $this->assertGreaterThanOrEqual(5, count($this->ids), 'enough order details for the test');
    }

    /** @var list<int> */
    private array $ids = [];

    /**
     * @return array<int, int> order detail id => state
     */
    private function states(): array
    {
        return $this->getTableLocator()->get('OrderDetails')->find('list', keyField: 'id_order_detail', valueField: 'order_state')
            ->orderBy(['id_order_detail' => 'ASC'])->toArray();
    }

    private function setPickupDay(int $orderDetailId, string $day, ?int $state = null): void
    {
        $table = $this->getTableLocator()->get('OrderDetails');
        $entity = $table->get($orderDetailId);
        $entity->pickup_day = $day;
        if ($state !== null) {
            $entity->order_state = $state;
        }
        $table->save($entity);
    }

    public function testMarksOnlyPastOrdersAsBilledAndCreatesNothingElse(): void
    {
        $billed = Configure::read('app.htmlHelper')->getOrderStateBilled();
        [$a, $b, $c, $d] = $this->ids;
        $this->setPickupDay($a, '2018-01-26', OrderDetail::STATE_OPEN);                       // past, open
        $this->setPickupDay($b, '2018-01-19', OrderDetail::STATE_ORDER_LIST_SENT_TO_MANUFACTURER); // past, list sent
        $this->setPickupDay($c, '2018-02-02', OrderDetail::STATE_OPEN);                       // run day itself: stays editable
        $this->setPickupDay($d, '2018-02-09', OrderDetail::STATE_OPEN);                       // future
        // customers that never get invoices are billed as well (the lines would pile up otherwise)
        $orderA = $this->getTableLocator()->get('OrderDetails')->get($a);
        $this->changeCustomer($orderA->id_customer, 'shopping_price', Customer::ZERO_PRICE);

        $before = $this->states();
        $queuedBefore = $this->getTableLocator()->get('Queue.QueuedJobs')->find()->count();
        $this->exec('send_invoices_to_customers "' . self::RUN_DAY . '"');
        $this->assertExitSuccess();
        $after = $this->states();

        $this->assertSame($billed, $after[$a]);
        $this->assertSame($billed, $after[$b]);
        $this->assertSame(OrderDetail::STATE_OPEN, $after[$c]);
        $this->assertSame(OrderDetail::STATE_OPEN, $after[$d]);
        foreach ($before as $id => $state) {
            $pickup = $this->getTableLocator()->get('OrderDetails')->get($id)->pickup_day->format('Y-m-d');
            if ($pickup >= '2018-02-02' || !in_array($state, [3, 10], true)) {
                $this->assertSame($state, $after[$id], 'order detail ' . $id . ' must not change');
            }
        }

        // no invoices, no numbers, no pdfs, no mails
        $this->assertSame(0, $this->getTableLocator()->get('Invoices')->find()->count());
        $this->assertSame(['.gitignore'], array_values(array_diff(scandir(Configure::read('app.folder_invoices')), ['.', '..'])));
        $this->assertSame($queuedBefore, $this->getTableLocator()->get('Queue.QueuedJobs')->find()->count(), 'no e-mail is queued');

        $log = $this->getTableLocator()->get('ActionLogs')->find()->where(['type' => 'orders_billed_without_invoices'])->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('ohne Rechnung als abgerechnet markiert', $log->text);

        // second run: nothing left to do, no new log entry content problem, no invoice
        $this->exec('send_invoices_to_customers "' . self::RUN_DAY . '"');
        $this->assertExitSuccess();
        $this->assertSame($after, $this->states());
        $this->assertSame(0, $this->getTableLocator()->get('Invoices')->find()->count());
    }

    public function testBilledLinesAreLeftAloneAndLaterRunDayCatchesUp(): void
    {
        $id = $this->ids[0];
        $this->setPickupDay($id, '2018-02-09', OrderDetail::STATE_OPEN);
        $this->exec('send_invoices_to_customers "' . self::RUN_DAY . '"');
        $this->assertSame(OrderDetail::STATE_OPEN, $this->states()[$id]);
        $this->exec('send_invoices_to_customers "2018-02-10 10:00:00"'); // next week's run
        $this->assertSame(Configure::read('app.htmlHelper')->getOrderStateBilled(), $this->states()[$id]);
    }

    public function testSettingIsEditableInTheAdminSettings(): void
    {
        $this->get('/admin/configurations');
        $this->assertResponseOk();
        $this->assertResponseContains('Bestellungen im Cronjob nur abrechnen');
        $this->get('/admin/configurations/edit/FCS_BILL_ORDERS_WITHOUT_INVOICES');
        $this->assertResponseOk();
        $this->assertResponseContains('ohne Rechnung, PDF und E-Mail');
    }
}
