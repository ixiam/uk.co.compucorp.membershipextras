<?php
use CRM_MembershipExtras_Service_MoneyUtilities as MoneyUtilities;
use CRM_MembershipExtras_Service_InstallmentReceiveDateCalculator as InstallmentReceiveDateCalculator;

/**
 * Renews the payment plan and the related memberships if it paid by once and
 * not using installments.
 *
 * Paid by once (no installments) payment plan get renewed by creating single
 * pending contribution that links to the already existing recurring
 * contribution.
 */
class CRM_MembershipExtras_Job_OfflineAutoRenewal_SingleInstallmentPlan extends CRM_MembershipExtras_Job_OfflineAutoRenewal_PaymentPlan {

  /**
   * List of line items to be renewed.
   *
   * @var array
   */
  private $linesToBeRenewed = [];

  /**
   * Obtains list of payment plans with a single installment that are ready to
   * be renewed. This means:
   *
   * 1- Recurring contribution is a manual payment plan
   * 2- Recurring contribution is set to auto-renew.
   * 3- Recurring contribution has no end date.
   * 4- Recurring contribution is not cancelled nor complete.
   * 5- Recurring contribution has either:
   *   - At least one auto-renew, un-removed line item for a membership with an
   *     end date before today + $daysToRenewInAdvance.
   *   - At least one auto-renew, un-removed line item and NO memberships, and
   *     next_run_date(*) is before today + $daysToRenewInAdvance.
   *
   * (*) next_run_date corresponds to either maximum end date of all line items
   * related to the recurring contribution + 1 period, or the start date of the
   * recurring contribution + 1 period, if there are no line items with end
   * dates.
   *
   * @return array
   */
  protected function getRecurringContributions() {
    $manualPaymentProcessorsIDs = implode(',', $this->manualPaymentProcessorIDs);
    $cancelledStatusID = $this->contributionStatusesNameMap['Cancelled'];
    $refundedStatusID = $this->contributionStatusesNameMap['Refunded'];
    $pendingStatusID = $this->contributionStatusesNameMap['Pending'];
    $daysToRenewInAdvance = $this->daysToRenewInAdvance;

    $query = "
      SELECT ccr.id as contribution_recur_id, ccr.installments,
        CASE
          WHEN frequency_unit = 'day' THEN DATE_ADD(
            CASE
              WHEN MAX(msl.end_date) IS NULL THEN ccr.start_date
              ELSE MAX(msl.end_date)
            END,
            INTERVAL frequency_interval DAY
          )
          WHEN frequency_unit = 'week' THEN DATE_ADD(
            CASE
              WHEN MAX(msl.end_date) IS NULL THEN ccr.start_date
              ELSE MAX(msl.end_date)
            END,
            INTERVAL frequency_interval WEEK
          )
          WHEN frequency_unit = 'month' THEN DATE_ADD(
            CASE
              WHEN MAX(msl.end_date) IS NULL THEN ccr.start_date
              ELSE MAX(msl.end_date)
            END,
            INTERVAL frequency_interval MONTH
          )
          WHEN frequency_unit = 'year' THEN DATE_ADD(
            CASE
              WHEN MAX(msl.end_date) IS NULL THEN ccr.start_date
              ELSE MAX(msl.end_date)
            END,
            INTERVAL frequency_interval YEAR
          )
        END AS next_run_date 
        FROM civicrm_contribution_recur ccr
   LEFT JOIN membershipextras_subscription_line msl ON msl.contribution_recur_id = ccr.id
   LEFT JOIN civicrm_line_item cli ON msl.line_item_id = cli.id
   LEFT JOIN civicrm_membership cm ON (cm.id = cli.entity_id AND cli.entity_table = 'civicrm_membership')
   LEFT JOIN civicrm_value_payment_plan_periods ppp ON ppp.entity_id = ccr.id
       WHERE (ccr.payment_processor_id IS NULL OR ccr.payment_processor_id IN ({$manualPaymentProcessorsIDs}))
         AND ccr.end_date IS NULL
         AND (
          ccr.installments < 2
          OR ccr.installments IS NULL
         )
         AND ccr.auto_renew = 1 
         AND (
          ccr.contribution_status_id != {$cancelledStatusID} 
          AND ccr.contribution_status_id != {$refundedStatusID}
         )
         AND ppp.next_period IS NULL
         AND msl.auto_renew = 1
         AND msl.is_removed = 0
         AND msl.end_date IS NULL
         
    GROUP BY ccr.id
      HAVING MIN(cm.end_date) <= DATE_ADD(CURDATE(), INTERVAL {$daysToRenewInAdvance} DAY)
      OR (
        COUNT(cm.id) = 0
        AND next_run_date <= DATE_ADD(CURDATE(), INTERVAL {$daysToRenewInAdvance} DAY)
      )
    ";
    $recurContributions = CRM_Core_DAO::executeQuery($query);

    $recurContributionsList = [];
    while ($recurContributions->fetch()) {
      $recurContribution['contribution_recur_id'] = $recurContributions->contribution_recur_id;
      $recurContribution['installments'] = $recurContributions->installments;
      $recurContributionsList[] = $recurContribution;
    }

    return $recurContributionsList;
  }

  /**
   * @inheritdoc
   */
  public function renew() {
    $this->endCurrentLineItemsAndCreateNewOnesForNextPeriod($this->currentRecurContributionID);
    $this->updateRecurringContributionAmount($this->currentRecurContributionID);
    $this->setNewRecurringContribution();
    $this->buildLineItemsParams();
    $this->setTotalAndTaxAmount();
    $this->paymentPlanStartDate = $this->calculateNoInstallmentsPaymentPlanStartDate();
    $this->membershipsStartDate = $this->calculateRenewedMembershipsStartDate() ?: $this->paymentPlanStartDate;

    $this->recordPaymentPlanFirstContribution();
    $this->renewPaymentPlanMemberships($this->currentRecurContributionID);
  }

  /**
   * Sets new recurring contribution from current recurring contribution.
   */
  private function setNewRecurringContribution() {
    $this->newRecurringContributionID = $this->currentRecurContributionID;
    $this->newRecurringContribution = civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $this->newRecurringContributionID,
    ]);
  }

  /**
   * Creates duplicates of line items associated to recurring contribution that
   * are set to auto-renew, don't have an end date and are not removed. It sets
   * end date for the old line items.
   *
   * @param $recurringContributionID
   */
  private function endCurrentLineItemsAndCreateNewOnesForNextPeriod($recurringContributionID) {
    $newStartDate = new DateTime($this->calculateNoInstallmentsPaymentPlanStartDate());
    $newEndDate = new DateTime($newStartDate->format('Y-m-d'));
    $newEndDate->sub(new DateInterval('P1D'));

    $lineItems = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $recurringContributionID,
      'is_removed' => 0,
      'end_date' => ['IS NULL' => 1],
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1]
      ],
      'options' => ['limit' => 0],
    ]);

    foreach ($lineItems['values'] as $line) {
      $this->endLineItem($line['id'], $newEndDate);

      if ($line['auto_renew']) {
        $this->duplicateSubscriptionLine($line, $newStartDate);
      }
    }
  }

  /**
   * Sets end date for given subscription line item ID.
   *
   * @param $lineID
   * @param \DateTime $endDate
   */
  private function endLineItem($lineID, DateTime $endDate) {
    CRM_MembershipExtras_BAO_ContributionRecurLineItem::create([
      'id' => $lineID,
      'end_date' => $endDate->format('Y-m-d'),
    ]);
  }

  /**
   * Duplicates given subscription line with the given start date.
   *
   * @param array $line
   * @param \DateTime $startDate
   */
  private function duplicateSubscriptionLine($line, DateTime $startDate) {
    $lineItemParams = $line['api.LineItem.getsingle'];
    $lineItemParams['unit_price'] = $this->calculateLineItemUnitPrice($lineItemParams);
    $lineItemParams['line_total'] = MoneyUtilities::roundToCurrencyPrecision($lineItemParams['unit_price'] * $lineItemParams['qty']);
    $lineItemParams['tax_amount'] = $this->calculateLineItemTaxAmount($lineItemParams['line_total'], $lineItemParams['financial_type_id']);
    unset($lineItemParams['id']);

    $newLineItem = civicrm_api3('LineItem', 'create', $lineItemParams);

    CRM_MembershipExtras_BAO_ContributionRecurLineItem::create([
      'contribution_recur_id' => $line['contribution_recur_id'],
      'line_item_id' => $newLineItem['id'],
      'start_date' => $startDate->format('Y-m-d'),
      'auto_renew' => 1,
    ]);
  }

  /**
   * Calculates the new start date for the payment plan
   * if its paid with no installments.
   * @return string
   */
  private function calculateNoInstallmentsPaymentPlanStartDate() {
    $currentRecurContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $this->currentRecurContributionID,
    ])['values'][0];
    $installmentReceiveDateCalculator = new InstallmentReceiveDateCalculator($currentRecurContribution);

    $paymentPlanContributionsCount = civicrm_api3('Contribution', 'getcount', [
      'contribution_recur_id' => $this->currentRecurContributionID,
      'contribution_status_id' => ['Pending', 'Completed'],
    ]);

    return $installmentReceiveDateCalculator->calculate($paymentPlanContributionsCount + 1);
  }

  /**
   * @inheritdoc
   */
  protected function getRecurringContributionLineItemsToBeRenewed($recurringContributionID) {
    if (!isset($this->linesToBeRenewed[$recurringContributionID])) {
      $q = '
      SELECT msl.*, li.*, m.end_date AS memberhsip_end_date
      FROM membershipextras_subscription_line msl, civicrm_line_item li
      LEFT JOIN civicrm_membership m ON li.entity_id = m.id
      WHERE msl.line_item_id = li.id
      AND msl.contribution_recur_id = %1
      AND msl.auto_renew = 1
      AND msl.is_removed = 0
      AND msl.end_date IS NULL
      ';
      $dbResultSet = CRM_Core_DAO::executeQuery($q, [
        1 => [$recurringContributionID, 'Integer'],
      ]);

      $this->linesToBeRenewed[$recurringContributionID] = [];
      while ($dbResultSet->fetch()) {
        $this->linesToBeRenewed[$recurringContributionID][] = $dbResultSet->toArray();
      }
    }

    return $this->linesToBeRenewed[$recurringContributionID];
  }

  /**
   * @inheritdoc
   */
  protected function getNewPaymentPlanActiveLineItems() {
    $lineItems = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $this->newRecurringContributionID,
      'is_removed' => 0,
      'end_date' => ['IS NULL' => 1],
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1],
      ],
    ]);

    if (!$lineItems['count']) {
      return [];
    }

    $result = [];
    foreach ($lineItems['values'] as $line) {
      $lineData = $line['api.LineItem.getsingle'];
      $result[] =  $lineData;
    }

    return $result;
  }

  /**
   * @inheritdoc
   */
  protected function calculateRecurringContributionTotalAmount($recurringContributionID) {
    $totalAmount = 0;

    $result = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $recurringContributionID,
      'start_date' => ['IS NOT NULL' => 1],
      'is_removed' => 0,
      'end_date' => ['IS NULL' => 1],
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1],
      ],
      'options' => ['limit' => 0],
    ]);

    if ($result['count'] > 0) {
      foreach ($result['values'] as $lineItemData) {
        $totalAmount += $lineItemData['api.LineItem.getsingle']['line_total'];
        $totalAmount += $lineItemData['api.LineItem.getsingle']['tax_amount'];
      }
    }

    return MoneyUtilities::roundToCurrencyPrecision($totalAmount);
  }

}
