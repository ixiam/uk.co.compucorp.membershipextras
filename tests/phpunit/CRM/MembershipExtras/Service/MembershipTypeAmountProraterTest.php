<?php

use CRM_MembershipExtras_Service_MembershipTypeDurationCalculator as MembershipTypeDurationCalculator;
use CRM_MembershipExtras_Service_MembershipTypeAmountProrater as MembershipTypeAmount;
use CRM_MembershipExtras_Service_MembershipTypeTaxAmountCalculator as MembershipTypeTaxAmountCalculator;
use CRM_MembershipExtras_Test_Fabricator_MembershipType as MembershipTypeFabricator;

/**
 * Class CRM_MembershipExtras_Service_MembershipTypeAmountTest
 *
 * @group headless
 */
class CRM_MembershipExtras_Service_MembershipTypeAmountProraterTest extends BaseHeadlessTest {

  public function testCalculateProRata() {
    $originalDurationDays = 360;
    $calculatedDays = 60;
    $membershipType = MembershipTypeFabricator::fabricate(['minimum_fee' => 120]);
    $expectedProrata = 20; //i.e 60/360 *120
    $membershipTypeDuration = $this->getMembershipTypeDuration($originalDurationDays, $calculatedDays);
    $taxAmount = 0;
    $membershipTypeTaxAmount = $this->getMembershipTypeTaxAmount($membershipType, $expectedProrata, $taxAmount);
    $membershipTypeAmount = new MembershipTypeAmount($membershipTypeDuration, $membershipTypeTaxAmount);
    $proRata = $membershipTypeAmount->calculateProRata($membershipType, new DateTime(), new DateTime());
    $this->assertEquals($expectedProrata, $proRata);
  }

  public function testCalculateProRataWithTax() {
    $originalDurationDays = 360;
    $calculatedDays = 60;
    $membershipType = MembershipTypeFabricator::fabricate(['minimum_fee' => 120], TRUE);
    $expectedProrata = 20; //i.e 60/360 *120 + 10tax
    $taxAmount = 30;
    $expectedTotal =  $expectedProrata + $taxAmount;
    $membershipTypeDuration = $this->getMembershipTypeDuration($originalDurationDays, $calculatedDays);
    $membershipTypeTaxAmount = $this->getMembershipTypeTaxAmount($membershipType, $expectedProrata, $taxAmount);
    $membershipTypeAmount = new MembershipTypeAmount($membershipTypeDuration, $membershipTypeTaxAmount);
    $proRata = $membershipTypeAmount->calculateProRata($membershipType, new DateTime(), new DateTime());
    $this->assertEquals($expectedTotal, $proRata);
  }

  private function getMembershipTypeDuration($originalDays, $calculatedDays) {
    $membershipTypeDurationCalculator = $this->prophesize(MembershipTypeDurationCalculator::class);
    $membershipTypeDurationCalculator->calculateOriginalInDays()->willReturn($originalDays);
    $membershipTypeDurationCalculator->calculateDaysBasedOnDates(new DateTime(), new DateTime(), NULL)->willReturn($calculatedDays);

    return $membershipTypeDurationCalculator->reveal();
  }

  private function getMembershipTypeTaxAmount($membershipType, $prorata, $amount = 0) {
    $membershipTypeTaxAmount = $this->prophesize(MembershipTypeTaxAmountCalculator::class);
    $membershipTypeTaxAmount->calculateTax($membershipType, $prorata)->willReturn($amount);

    return $membershipTypeTaxAmount->reveal();
  }
}

