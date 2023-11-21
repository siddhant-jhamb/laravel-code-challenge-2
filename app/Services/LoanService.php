<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\ReceivedRepayment;
use App\Models\ScheduledRepayment;
use App\Models\User;
use Illuminate\Support\Carbon;

class LoanService
{
    /**
     * Create a Loan
     *
     * @param  User  $user
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  int  $terms
     * @param  string  $processedAt
     *
     * @return Loan
     */
    public function createLoan(User $user, int $amount, string $currencyCode, int $terms, string $processedAt): Loan
    {
        $loan = Loan::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'terms' => $terms,
            'outstanding_amount' => $amount,
            'processed_at' => $processedAt,
            'status' => Loan::STATUS_DUE
        ]);

        for ($i = 0; $i < $terms; $i++) {
            $repaymentAmount = intdiv($amount, $terms - $i);
            $amount = $amount - $repaymentAmount;
            ScheduledRepayment::create([
                'loan_id' => $loan->id,
                'amount' => $repaymentAmount,
                'outstanding_amount' => $repaymentAmount,
                'currency_code' => $currencyCode,
                'status' => ScheduledRepayment::STATUS_DUE,
                'due_date' => Carbon::parse($processedAt)->addMonths($i + 1)->toDateString()
            ]);
        }

        return $loan;
    }

    /**
     * Repay Scheduled Repayments for a Loan
     *
     * @param  Loan  $loan
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  string  $receivedAt
     *
     * @return Loan
     */
    public function repayLoan(Loan $loan, int $amount, string $currencyCode, string $receivedAt): Loan
    {
        $scheduledRepayments = $loan->scheduledRepayments;
        $paidAmount = 0;
        $outstandingAmount = 0;

        foreach ($scheduledRepayments as $scheduledRepayment) {
            if ($scheduledRepayment->outstanding_amount == 0 &&
                $scheduledRepayment->status == ScheduledRepayment::STATUS_DUE) {
                $scheduledRepayment->outstanding_amount = $scheduledRepayment->amount;
            }
            // If this repayment is already paid, move to the next.
            switch ($scheduledRepayment->status) {
                case ScheduledRepayment::STATUS_REPAID:
                    break;
                case ScheduledRepayment::STATUS_PARTIAL:
                    $outstandingAmount += $scheduledRepayment->outstanding_amount;
                    if ($amount >= $outstandingAmount) {
                        $scheduledRepayment->status = ScheduledRepayment::STATUS_REPAID;
                        $scheduledRepayment->outstanding_amount = 0;
                    } else {
                        $scheduledRepayment->outstanding_amount = $scheduledRepayment->outstanding_amount - $amount;
                    }
                    $scheduledRepayment->save();
                    break;
                case ScheduledRepayment::STATUS_DUE:
                    $outstandingAmount = $scheduledRepayment->outstanding_amount;
                    if ($amount > 0) {
                        if ($amount >= $outstandingAmount) {
                            $paidAmount += $outstandingAmount;
                            $scheduledRepayment->status = ScheduledRepayment::STATUS_REPAID;
                            $scheduledRepayment->outstanding_amount = 0;
                            $amount = $amount - $outstandingAmount;
                        } else {
                            $paidAmount += $amount;
                            $scheduledRepayment->outstanding_amount = $scheduledRepayment->outstanding_amount - $amount;
                            $scheduledRepayment->status = ScheduledRepayment::STATUS_PARTIAL;
                            $amount = 0;
                        }
                        $scheduledRepayment->save();
                    }
                    break;
            }
        }

        ReceivedRepayment::create([
            'loan_id' => $loan->id,
            'amount' => $paidAmount,
            'currency_code' => $currencyCode,
            'received_at' => $receivedAt
        ]);

        $loan->outstanding_amount = $loan->outstanding_amount - $paidAmount;
        if ($loan->outstanding_amount == 0) {
            $loan->status = Loan::STATUS_REPAID;
        }
        $loan->save();

        return $loan;
    }
}
