<?php
/**
 * MailHook — Smart Routing Logic
 *
 * Evaluates configured rules to determine if an email should
 * be routed through a specific SMTP connection.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Routing {

    /**
     * Stored plugin settings.
     *
     * @var array
     */
    private $settings;

    public function __construct() {
        $this->settings = get_option( 'mailhook_settings', array() );

        // Only register if routing is enabled and rules exist
        if ( isset( $this->settings['routing_enabled'] ) && $this->settings['routing_enabled'] === '1' && 
             ! empty( $this->settings['routing_rules'] ) && is_array( $this->settings['routing_rules'] ) ) {
            
            // Hook into phpmailer_init before the default configuration (priority 99)
            // but after common filters like wp_mail (priority 10)
            add_action( 'phpmailer_init', array( $this, 'evaluate_routing' ), 50 );
        }
    }

    /**
     * Evaluate rules and apply connection override if a match is found.
     *
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance.
     */
    public function evaluate_routing( $phpmailer ) {
        $rules = $this->settings['routing_rules'] ?? array();
        
        foreach ( $rules as $rule ) {
            if ( empty( $rule['connection_id'] ) || empty( $rule['groups'] ) ) {
                continue;
            }

            if ( $this->match_rule( $rule, $phpmailer ) ) {
                $connection = $this->get_connection_by_id( $rule['connection_id'] );
                if ( $connection ) {
                    if ( class_exists( 'MailHook_Mailer' ) ) {
                        MailHook_Mailer::set_connection_override( $connection );
                    }
                    break; // Stop at first matching rule
                }
            }
        }
    }

    /**
     * Match a single rule against the PHPMailer object.
     *
     * @param array                         $rule      The rule definition.
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance.
     * @return bool
     */
    private function match_rule( $rule, $phpmailer ) {
        // Groups use OR logic
        foreach ( $rule['groups'] as $group ) {
            if ( $this->match_group( $group, $phpmailer ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match a group of conditions against the PHPMailer object.
     *
     * @param array                         $group     The group of conditions.
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance.
     * @return bool
     */
    private function match_group( $group, $phpmailer ) {
        if ( empty( $group['conditions'] ) ) {
            return false;
        }

        // Conditions within a group use AND logic
        foreach ( $group['conditions'] as $condition ) {
            if ( ! $this->match_condition( $condition, $phpmailer ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Match a single condition against the PHPMailer object.
     *
     * @param array                         $condition The condition definition (field, operator, value).
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance.
     * @return bool
     */
    private function match_condition( $condition, $phpmailer ) {
        $field    = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'contains';
        $value    = $condition['value'] ?? '';

        $haystack = $this->get_field_value( $field, $phpmailer );

        if ( is_array( $haystack ) ) {
            foreach ( $haystack as $item ) {
                if ( $this->compare( $item, $operator, $value ) ) {
                    return true;
                }
            }
            return false;
        }

        return $this->compare( $haystack, $operator, $value );
    }

    /**
     * Get the value of a specific field from the PHPMailer object.
     *
     * @param string                        $field     The field name ('subject', 'to', 'from', 'body').
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance.
     * @return string|array
     */
    private function get_field_value( $field, $phpmailer ) {
        switch ( $field ) {
            case 'subject':
                return $phpmailer->Subject;
            case 'to':
                // PHPMailer->getToAddresses() returns array of [email, name]
                $addresses = array();
                foreach ( $phpmailer->getToAddresses() as $addr ) {
                    $addresses[] = $addr[0];
                }
                return $addresses;
            case 'from':
                return $phpmailer->From;
            case 'body':
                return $phpmailer->Body;
            default:
                return '';
        }
    }

    /**
     * Compare haystack and needle using the specified operator.
     *
     * @param string $haystack The value from PHPMailer.
     * @param string $operator The operator.
     * @param string $needle   The value from settings.
     * @return bool
     */
    private function compare( $haystack, $operator, $needle ) {
        $haystack = mb_strtolower( (string) $haystack );
        $needle   = mb_strtolower( (string) $needle );

        switch ( $operator ) {
            case 'contains':
                return strpos( $haystack, $needle ) !== false;
            case 'not_contains':
                return strpos( $haystack, $needle ) === false;
            case 'equals':
                return $haystack === $needle;
            case 'starts_with':
                return strpos( $haystack, $needle ) === 0;
            case 'ends_with':
                return substr( $haystack, -strlen( $needle ) ) === $needle;
            default:
                return false;
        }
    }

    /**
     * Get an additional connection by its ID.
     *
     * @param string $id The connection ID.
     * @return array|null
     */
    private function get_connection_by_id( $id ) {
        if ( empty( $this->settings['additional_connections'] ) ) {
            return null;
        }

        foreach ( $this->settings['additional_connections'] as $conn ) {
            if ( $conn['id'] === $id ) {
                return $conn;
            }
        }

        return null;
    }
}
