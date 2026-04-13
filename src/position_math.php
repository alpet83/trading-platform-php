<?php

    final class PositionMath {
        /**
         * Incremental anchor update from last known state.
         *
         * Returns [avg_pos_price, pos_after].
         */
        public static function advance_anchor(
            float $pos_before,
            float $avg_before,
            float $delta_pos,
            float $fill_price
        ): array {
            $pos_after = $pos_before + $delta_pos;
            $avg_after = self::next_avg_pos_price($pos_before, $pos_after, $avg_before, $fill_price);
            return [$avg_after, $pos_after];
        }

        /**
         * Returns next avg entry price for active position segment.
         *
         * Inputs are signed positions before/after fill in natural qty.
         * The function updates average only when absolute position increased
         * (opening inventory was added). Reducing/closing keeps average unchanged.
         */
        public static function next_avg_pos_price(
            float $pos_before,
            float $pos_after,
            float $avg_before,
            float $fill_price
        ): float {
            $fill_price = max(0.0, $fill_price);
            if ($fill_price <= 0.0) {
                return max(0.0, $avg_before);
            }

            $abs_before = abs($pos_before);
            $abs_after = abs($pos_after);

            // Fully flat after execution: no active segment.
            if ($abs_after <= 0.0) {
                return 0.0;
            }

            // No opening growth in absolute exposure: keep previous average.
            if ($abs_after <= $abs_before) {
                return max(0.0, $avg_before);
            }

            $dir_before = signval($pos_before);
            $dir_after = signval($pos_after);

            // New segment after zero-cross (or from flat): anchor to fill price.
            if (0 == $dir_before || $dir_before != $dir_after || $avg_before <= 0.0) {
                return $fill_price;
            }

            // Same-side position increase: weighted-average update.
            $open_added = $abs_after - $abs_before;
            if ($open_added <= 0.0) {
                return max(0.0, $avg_before);
            }

            $weighted = ($abs_before * $avg_before) + ($open_added * $fill_price);
            return $weighted / $abs_after;
        }
    }
