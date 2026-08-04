<?php
// Stubs for missing functions to avoid fatal errors during CI or initial tests.
// These stubs should be replaced by real implementations. Each stub throws
// an exception with guidance to create a proper implementation and link
// to an issue.

if (!function_exists('apply_transaction_balances')) {
    function apply_transaction_balances(...$args)
    {
        throw new \RuntimeException('apply_transaction_balances is not implemented. Please implement this function or include the correct module. See issue #TODO');
    }
}

if (!function_exists('php_recalculate_invoice_payment')) {
    function php_recalculate_invoice_payment(...$args)
    {
        throw new \RuntimeException('php_recalculate_invoice_payment is not implemented. Please implement this function or include the correct module. See issue #TODO');
    }
}

if (!function_exists('fn_get_next_sequence')) {
    function fn_get_next_sequence(...$args)
    {
        throw new \RuntimeException('fn_get_next_sequence is not implemented. Please implement this function or include the correct module. See issue #TODO');
    }
}

if (!function_exists('php_post_invoice')) {
    function php_post_invoice(...$args)
    {
        throw new \RuntimeException('php_post_invoice is not implemented. Please implement this function or include the correct module. See issue #TODO');
    }
}

if (!function_exists('php_delete_financial_transaction_and_reverse')) {
    function php_delete_financial_transaction_and_reverse(...$args)
    {
        throw new \RuntimeException('php_delete_financial_transaction_and_reverse is not implemented. Please implement this function or include the correct module. See issue #TODO');
    }
}

// Add additional stubs here as discovered during testing.
