<?php

namespace App\Services;

class ConfigSchema {
    public static function validate(array $config): array {
        $errors = [];
        if (!isset($config['periods']) || !is_array($config['periods'])) {
            $errors[] = 'periods phải là mảng.';
        }
        foreach (['auth_sheet_id', 'auth_gid', 'admin_password', 'col_emp_id', 'col_password', 'check_api_url', 'check_available_from', 'check_available_until', 'check_month_days', 'employee_notice'] as $key) {
            if (isset($config[$key]) && !is_string($config[$key])) {
                $errors[] = $key . ' phải là chuỗi.';
            }
        }
        foreach (['check_enabled', 'payroll_share_enabled'] as $key) {
            if (isset($config[$key]) && !is_bool($config[$key])) {
                $errors[] = $key . ' phải là boolean.';
            }
        }
        foreach (['payroll_share_ttl_hours', 'employee_session_timeout_minutes'] as $key) {
            if (isset($config[$key]) && !is_int($config[$key])) {
                $errors[] = $key . ' phải là số nguyên.';
            }
        }
        if (isset($config['auth_source_type']) && !in_array($config['auth_source_type'], ['google', 'local'], true)) {
            $errors[] = 'auth_source_type phải là google hoặc local.';
        }
        if (isset($config['periods']) && is_array($config['periods'])) {
            foreach ($config['periods'] as $idx => $period) {
                if (!is_array($period)) {
                    $errors[] = 'periods[' . $idx . '] phải là object.';
                    continue;
                }
                if (isset($period['source_type']) && !in_array($period['source_type'], ['google', 'local'], true)) {
                    $errors[] = 'periods[' . $idx . '].source_type phải là google hoặc local.';
                }
                foreach (['label', 'local_file', 'sheet_id', 'gid', 'cols', 'highlight_cols', 'money_cols', 'publish_date', 'sheet_name'] as $key) {
                    if (isset($period[$key]) && !is_string($period[$key])) {
                        $errors[] = 'periods[' . $idx . '].' . $key . ' phải là chuỗi.';
                    }
                }
                if (isset($period['enabled']) && !is_bool($period['enabled'])) {
                    $errors[] = 'periods[' . $idx . '].enabled phải là boolean.';
                }
                if (isset($period['sheet_index']) && !is_int($period['sheet_index'])) {
                    $errors[] = 'periods[' . $idx . '].sheet_index phải là số nguyên.';
                }
            }
        }
        return $errors;
    }
}
