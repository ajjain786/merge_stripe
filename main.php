<?php 
try {
        if (!empty($mainStripe->cs_stripe_id) && !empty($secStripe->cs_stripe_id)) {
            $mainCustomer = \Stripe\Customer::retrieve($mainStripe->cs_stripe_id);
            $secCustomer  = \Stripe\Customer::retrieve($secStripe->cs_stripe_id);

            \Stripe\Customer::update($mainStripe->cs_stripe_id, [
                'description' => "Merged From: {$secCustomer->email}",
                'metadata' => [
                    'merged_from' => $secStripe->cs_stripe_id,
                    'merge_date' => date('Y-m-d H:i:s')
                ]
            ]);

            \Stripe\Customer::update($secStripe->cs_stripe_id, [
                'metadata' => [
                    'merged_into' => $mainStripe->cs_stripe_id,
                    'merge_date' => date('Y-m-d H:i:s'),
                    'old_email' => $secCustomer->email,
                    'old_name' => $secCustomer->name,
                ],
                'description' => "Merged into: {$mainCustomer->email}",
            ]);

            // Step 1: Check if main customer has card
            $paymentMethods = \Stripe\PaymentMethod::all([
                'customer' => $mainStripe->cs_stripe_id,
                'type' => 'card',
            ]);

            if (empty($paymentMethods->data)) {
                $secCards = \Stripe\PaymentMethod::all([
                    'customer' => $secStripe->cs_stripe_id,
                    'type' => 'card',
                ]);

                if (!empty($secCards->data)) {
                    $secCard = $secCards->data[0];

                    try {
                        if ($secCard->customer !== $mainStripe->cs_stripe_id) {
                            // Try to attach if it's not already
                            $attachedCard = \Stripe\PaymentMethod::retrieve($secCard->id);
                            if ($attachedCard->customer != $mainStripe->cs_stripe_id) {
                                // Don't detach — just attempt attach
                                $attachedCard->attach([
                                    'customer' => $mainStripe->cs_stripe_id,
                                ]);
                            }
                        }

                        // Set as default
                        \Stripe\Customer::update($mainStripe->cs_stripe_id, [
                            'invoice_settings' => [
                                'default_payment_method' => $secCard->id,
                            ],
                        ]);

                        $paymentMethods = \Stripe\PaymentMethod::all([
                            'customer' => $mainStripe->cs_stripe_id,
                            'type' => 'card',
                        ]);
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        logMessage("❌ Cannot attach card from secStripe: " . $e->getMessage());
                    }
                } else {
                    logMessage("❌ No card found on either account. Cannot migrate subscriptions.");
                    return;
                }
            }

            // Step 2: Collect active subscriptions on main
            $mainSubscriptions = \Stripe\Subscription::all([
                'customer' => $mainStripe->cs_stripe_id,
                'status' => 'active',
            ]);

            $mainPriceIds = [];
            foreach ($mainSubscriptions->data as $mainSub) {
                foreach ($mainSub->items->data as $item) {
                    $mainPriceIds[] = $item->price->id;
                }
            }

            // Step 3: Migrate subscriptions from secStripe
            if (!empty($paymentMethods->data)) {
                $subscriptions = \Stripe\Subscription::all([
                    'customer' => $secStripe->cs_stripe_id,
                    'status' => 'active',
                ]);

                foreach ($subscriptions->data as $subscription) {
                    $isDuplicate = true;
                    foreach ($subscription->items->data as $item) {
                        if (!in_array($item->price->id, $mainPriceIds)) {
                            $isDuplicate = false;
                            break;
                        }
                    }

                    if ($isDuplicate) {
                        \Stripe\Subscription::retrieve($subscription->id)->cancel();
                        logMessage("🔁 Duplicate subscription ({$subscription->id}) found. Cancelled in secStripe ($seccid)");
                        continue;
                    }

                    // Create trial_end logic
                    $trialEnd = null;
                    $currentTime = time();

                    if (!empty($subscription->trial_end) && $subscription->trial_end > $currentTime) {
                        $trialEnd = $subscription->trial_end;
                    } else {
                        $periodEnd = $subscription->current_period_end;
                        if ($periodEnd > $currentTime) {
                            $trialEnd = $currentTime + ($periodEnd - $currentTime);
                        }
                    }

                    $items = [];
                    foreach ($subscription->items->data as $item) {
                        $items[] = [
                            'price' => $item->price->id,
                            'quantity' => $item->quantity,
                        ];
                    }

                    // Create new subscription for main
                    try {
                        $newSubscription = \Stripe\Subscription::create([
                            'customer' => $mainStripe->cs_stripe_id,
                            'items' => $items,
                            'default_payment_method' => $paymentMethods->data[0]->id,
                            'metadata' => [
                                'migrated_from' => $secStripe->cs_stripe_id,
                                'original_subscription_id' => $subscription->id
                            ],
                            'proration_behavior' => 'none',
                            'trial_end' => $trialEnd ?: 'now',
                        ]);

                        // Cancel old subscription
                        $subscription->cancel();
                        logMessage("✅ Migrated subscription ({$subscription->id}) from $seccid to $maincid");

                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        logMessage("❌ Subscription migration failed: " . $e->getMessage());
                    }
                }
            }

            // Final update: set secondary customer info to match main
            \Stripe\Customer::update($secStripe->cs_stripe_id, [
                'email' => $mainCustomer->email,
                'name' => $mainCustomer->name,
                'phone' => $mainCustomer->phone,
            ]);

            logMessage("✅ Successfully merged $seccid into $maincid");
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        logMessage("❌ Stripe API error: " . $e->getMessage());
    } catch (Exception $e) {
        logMessage("❌ General error: " . $e->getMessage());
    }
?>
