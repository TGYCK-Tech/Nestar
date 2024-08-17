<?php
/**
 * Plugin Name: Custom ID Verification - Nestar
 * Description: A custom plugin that integrates Forminator with a custom tenant ID verification process during registration, including both paid Stripe verification and student ID upload, with security enhancements.
 * Version: 1.0
 * Author: Allan and Amit
 */
 
require_once plugin_dir_path(__FILE__) . 'stripe-php/init.php'; // Adjust the path as needed

class ID_Verification_Plugin {

    private $user_data = [];

    public function __construct() {
        add_filter('forminator_custom_form_submit_field_data', [$this, 'store_user_data'], 10, 2);
        add_action('forminator_form_after_save_entry', [$this, 'handle_verification'], 10, 2);
        add_action('init', [$this, 'register_verification_complete_endpoint']);
        add_action('template_redirect', [$this, 'handle_verification_complete']);
    }

    public function store_user_data($field_data_array, $form_id) {
        // Only proceed if the correct form is submitted
        if ($form_id != '1321') {
            return $field_data_array;
        }

        // Initialize variables to store the required values
        $email = '';
        $user_role = '';
        $verification_method = '';

        // Loop through the field data array to extract the values
        foreach ($field_data_array as $field) {
            switch ($field['name']) {
                case 'email-1':
                    $email = sanitize_email($field['value']);
                    break;
                case 'select-1':
                    $user_role = sanitize_text_field($field['value']);
                    break;
                case 'radio-1':
                    $verification_method = sanitize_text_field($field['value']);
                    break;
            }
        }

        // Store the data in the class property for later use
        $this->user_data = [
            'email' => $email,
            'user_role' => $user_role,
            'verification_method' => $verification_method,
        ];

        // Log the stored data for debugging
        error_log('User data stored: ' . print_r($this->user_data, true));

        return $field_data_array;
    }

    public function handle_verification($form_id, $entry) {
        
        error_log('handle_verification has triggered');
        // Only proceed if the correct form is submitted
        error_log('form_id is:' .$form_id);
        error_log('entry is:' .print_r($entry,true));

        
        if ($form_id != 1321 || $entry['success'] != 1) {
            return;
        }

        // Access the stored user data
        $email = $this->user_data['email'];
        $verification_method = $this->user_data['verification_method'];

        // Log the stored data for debugging
        error_log('Retrieved user data: ' . print_r($this->user_data, true));

        // Get the user by email
        $user = get_user_by('email', $email);

        if ($user) {
            // Log the user ID
            error_log('User found with ID: ' . $user->ID);

            // Unapprove the user immediately after form submission
            update_user_meta($user->ID, 'wdk_is_not_activated', 1);
            error_log('User unapproved (wdk_is_not_activated set to 1) with ID: ' . $user->ID);
        } else {
            error_log('User not found for email: ' . $email);
            wp_die('User not found.');
        }

        // Handle verification process
        if ($verification_method == 'one') { // Adjust based on the actual value returned
            // Log the start of the Stripe verification session creation
            error_log('Starting Stripe verification session creation for email: ' . $email);

            // Create the Stripe verification session
            $session = $this->create_stripe_verification_session($email);

            if ($session) {
                // Log successful session creation
                error_log('Stripe verification session created successfully. Session ID: ' . $session->id);
    
                // Store the session ID in user meta
                update_user_meta($user->ID, 'verification_session_id', $session->id);
    
                // Output JavaScript to redirect the user
                echo '<!DOCTYPE html>';
                echo '<html lang="en">';
                echo '<head><meta charset="UTF-8"><meta http-equiv="refresh" content="2;url=' . $session->url . '"><title>Redirecting...</title></head>';
                echo '<body>';
                echo '<p>Redirecting, please wait...</p>';
                echo '<script type="text/javascript">';
                echo 'window.location.href="' . $session->url . '";';
                echo '</script>';
                echo '</body>';
                echo '</html>';
                exit;
            } else {
                error_log('Failed to create Stripe verification session.');
                wp_die('Failed to create verification session. Please try again.');
            }
        } else {
            error_log('No Government ID Verification selected. No further action taken.');
        }
    }

    private function create_stripe_verification_session($email) {
        \Stripe\Stripe::setApiKey('sk_test_51N8sRLHT8o4Rj1KA9XRNF2HNvD9d7dOlLGe4VAHwdwCeIPxBZLBr9QcfG618Ltu5yNLqF7HVG4v4WgBb8khChSGe0001RxP0JJ');

        try {
            $session = \Stripe\Identity\VerificationSession::create([
                'type' => 'document',
                'metadata' => [
                    'email' => $email,
                ],
                'return_url' => site_url('/verification-complete/'),
            ]);

            return $session;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function register_verification_complete_endpoint() {
        add_rewrite_endpoint('verification-complete', EP_ROOT);
    }

    public function handle_verification_complete() {
        global $wp_query;

        if (!isset($wp_query->query_vars['verification-complete'])) {
            error_log('Verification complete endpoint not triggered.');
            return;
        }

        $session_id = $_GET['session_id']; // Get the session ID from the query string
        error_log('Verification complete triggered. Session ID: ' . $session_id);

        if ($session_id) {
            \Stripe\Stripe::setApiKey('your-stripe-secret-key');

            try {
                $session = \Stripe\Identity\VerificationSession::retrieve($session_id);

                if ($session && $session->status === 'verified') {
                    $email = $session->metadata->email;
                    error_log('Verification session verified. Email: ' . $email);

                    $user = get_user_by('email', $email);

                    if ($user) {
                        wdk_approve($user->ID);
                        error_log('User approved with ID: ' . $user->ID);
                        wp_redirect(site_url('/verification-success/'));
                        exit;
                    } else {
                        error_log('User not found for email: ' . $email);
                    }
                } else {
                    error_log('Verification failed or incomplete for session ID: ' . $session_id);
                    wp_die('Verification failed or incomplete. Please contact support.');
                }
            } catch (\Stripe\Exception\ApiErrorException $e) {
                error_log('Stripe API error: ' . $e->getMessage());
                wp_die('Stripe error encountered. Please contact support.');
            }
        } else {
            error_log('No session ID provided in the URL.');
        }
    }
}

new ID_Verification_Plugin();