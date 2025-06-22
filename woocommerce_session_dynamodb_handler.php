<?php
/**
 * Plugin Name: WooCommerce DynamoDB Session Handler
 * Description: This class implements a custom session handler for WooCommerce that stores session data in AWS DynamoDB instead of MySQL
 * Version: 1.0
 * Author: Tiến Minh
 */

require_once __DIR__ . '/vendor/autoload.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\Exception\AwsException;

class WC_DynamoDB_Session_Handler extends WC_Session_Handler {
    
    private $dynamodb_client;
    private $table_name;
    private $region;
    private $log_file_path;

    public function __construct() {
        // Set up logging
        $this->log_file_path = plugin_dir_path(__FILE__) . 'logs/dynamodb-sync.log';
        $log_dir = dirname($this->log_file_path);
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        $this->write_log('Plugin initialization started', 'DEBUG');

        // Define DynamoDB settings
        $this->region = 'us-east-1'; // Adjust to your AWS region
        $this->table_name = 'woocommerce_sessions';

        // Initialize DynamoDB client with enhanced error handling
        try {
            $this->dynamodb_client = new DynamoDbClient([
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => [
                    'key' => '',    // Replace with your AWS Access Key
                    'secret' => '' // Replace with your AWS Secret Key
                ]
            ]);
            $this->write_log('DynamoDB client initialized successfully', 'INFO');
        } catch (AwsException $e) {
            $this->write_log('Error initializing DynamoDB client: ' . $e->getMessage(), 'ERROR');
            return; // Exit constructor if client initialization fails
        }

        // Verify client is initialized before proceeding
        if ($this->dynamodb_client === null) {
            $this->write_log('DynamoDB client is null, aborting initialization', 'ERROR');
            return; // Prevent further execution if client is not set
        }

        // Create table if it doesn't exist
        $this->create_table_if_not_exists();

        // --- REFINED INITIALIZATION LOGIC FOR EXPIRY ONLY ---
        // Crucially, call the parent constructor first. This sets up _customer_id, _session_expiry, etc.
        parent::__construct(); 

        // After the parent constructor has run, _session_expiry *should* be set.
        // If it's still not a valid positive integer, force a default expiry.
        if (empty($this->_session_expiry) || !is_numeric($this->_session_expiry) || intval($this->_session_expiry) <= 0) {
            $this->write_log('WARNING: _session_expiry was not properly set by parent or was invalid (' . $this->_session_expiry . '). Forcing re-calculation.', 'WARNING');
            $this->set_session_expiration(); // Re-call this to try and get a proper value

            // If it's *still* invalid after attempting to re-set, as a last resort,
            // manually set it to a reasonable default (e.g., current time + 2 days for guest/logged out,
            // or WooCommerce's default session length which is typically 2 days).
            // A more robust way might be to inspect WC_Session_Handler's set_session_expiration().
            if (empty($this->_session_expiry) || !is_numeric($this->_session_expiry) || intval($this->_session_expiry) <= 0) {
                 $default_expiry_seconds = apply_filters( 'woocommerce_session_expiration', 60 * 60 * 48 ); // 48 hours
                 $this->_session_expiry = time() + $default_expiry_seconds;
                 $this->write_log('CRITICAL: _session_expiry remained invalid. Manually setting to: ' . $this->_session_expiry, 'CRITICAL');
            }
        }
        
        // Load session data. This should always happen after _customer_id is definitively set by parent::__construct.
        $this->_data = $this->get_session_data();

        // Register session save handlers
        add_action('woocommerce_set_cart_cookies', array($this, 'set_customer_session_cookie'), 10);
        add_action('shutdown', array($this, 'save_data'), 20);
        add_action('wp_logout', array($this, 'destroy_session'));

        if (!is_user_logged_in()) {
            add_filter('nonce_user_logged_out', array($this, 'nonce_user_logged_out'));
        }
    }
    
    /**
     * Create DynamoDB table if it doesn't exist
     */
    public function create_table_if_not_exists() {
        try {
            // Check if table exists
            $this->dynamodb_client->describeTable([
                'TableName' => $this->table_name
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                // Table doesn't exist, create it
                $this->create_session_table();
            } else {
                throw $e;
            }
        }
    }
    
    /**
     * Create the DynamoDB table for sessions
     */
    private function create_session_table() {
        try {
            $this->dynamodb_client->createTable([
                'TableName' => $this->table_name,
                'KeySchema' => [
                    [
                        'AttributeName' => 'session_key',
                        'KeyType' => 'HASH'
                    ]
                ],
                'AttributeDefinitions' => [
                    [
                        'AttributeName' => 'session_key',
                        'AttributeType' => 'S'
                    ],
                    [
                        'AttributeName' => 'session_expiry',
                        'AttributeType' => 'N'
                    ]
                ],
                'GlobalSecondaryIndexes' => [
                    [
                        'IndexName' => 'session_expiry-index',
                        'KeySchema' => [
                            [
                                'AttributeName' => 'session_expiry',
                                'KeyType' => 'HASH'
                            ]
                        ],
                        'Projection' => [
                            'ProjectionType' => 'KEYS_ONLY'
                        ],
                        'ProvisionedThroughput' => [
                            'ReadCapacityUnits' => 5,
                            'WriteCapacityUnits' => 5
                        ]
                    ]
                ],
                'ProvisionedThroughput' => [
                    'ReadCapacityUnits' => 10,
                    'WriteCapacityUnits' => 10
                ]
            ]);
            
            // Wait for table to be created
            $this->dynamodb_client->waitUntil('TableExists', [
                'TableName' => $this->table_name,
                '@waiter' => [
                    'delay' => 5,
                    'maxAttempts' => 20
                ]
            ]);
            $this->write_log('DynamoDB table created successfully', 'INFO');
        } catch (AwsException $e) {
            $this->write_log('Error creating DynamoDB table: ' . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Get session data from DynamoDB
     */
    public function get_session_data() {
        try {
            if (empty($this->_customer_id)) {
                $this->write_log('Attempted to get session data but _customer_id is empty.', 'DEBUG');
                return array();
            }

            $result = $this->dynamodb_client->getItem([
                'TableName' => $this->table_name,
                'Key' => [
                    'session_key' => [
                        'S' => (string)$this->_customer_id // Ensure it's a string, even if numeric
                    ]
                ]
            ]);
            
            if (isset($result['Item'])) {
                $session_data = isset($result['Item']['session_value']) ? 
                    $result['Item']['session_value']['S'] : '';
                $this->write_log('Session data retrieved for key: ' . $this->_customer_id, 'DEBUG');
                return maybe_unserialize($session_data);
            }
            $this->write_log('No session data found for key: ' . $this->_customer_id, 'DEBUG');
        } catch (AwsException $e) {
            $this->write_log('Error getting session data from DynamoDB: ' . $e->getMessage(), 'ERROR');
        }
        
        return array();
    }
    
    /**
     * Save session data to DynamoDB
     */
    public function save_data($old_session_key = '')
    {
        // Ensure _customer_id is set before attempting to save
        if (empty($this->_customer_id)) {
            $this->write_log('Cannot save session data: _customer_id is empty. Aborting save.', 'ERROR');
            return;
        }

        if ($this->_dirty && $this->has_session()) {
            try {
                // Defensive check: ensure _session_expiry is a valid integer before saving
                if (empty($this->_session_expiry) || !is_numeric($this->_session_expiry) || intval($this->_session_expiry) <= 0) {
                    $this->write_log('WARNING: _session_expiry was empty, non-numeric, or non-positive in save_data; re-setting it.', 'WARNING');
                    $this->set_session_expiration(); 
                }

                $expiry = intval($this->_session_expiry);
                // Final validation: if after re-setting, it's still invalid, log and exit.
                if ($expiry <= 0) {
                    // As a final, final fallback for save_data if all else fails, set a default.
                    // This is a last resort to prevent the save from failing entirely.
                    $default_expiry_seconds = apply_filters( 'woocommerce_session_expiration', 60 * 60 * 48 ); // 48 hours
                    $expiry = time() + $default_expiry_seconds;
                    $this->write_log('CRITICAL: _session_expiry remained invalid after re-setting. Forcing default expiry: ' . $expiry, 'CRITICAL');
                }

                $this->dynamodb_client->putItem([
                    'TableName' => $this->table_name,
                    'Item' => [
                        'session_key' => [
                            'S' => (string)$this->_customer_id // Ensure string type for DynamoDB
                        ],
                        'session_value' => [
                            'S' => maybe_serialize($this->_data)
                        ],
                        'session_expiry' => [
                            'N' => (string)$expiry // Ensure numeric value as string for DynamoDB
                        ]
                    ]
                ]);
                $this->_dirty = false;
                $this->write_log('Session data saved for key: ' . $this->_customer_id . ' with expiry: ' . $expiry, 'INFO');
            } catch (AwsException $e) {
                $this->write_log('Error saving session data to DynamoDB: ' . $e->getMessage(), 'ERROR');
            } catch (Exception $e) {
                $this->write_log('Validation error in save_data: ' . $e->getMessage(), 'ERROR');
            }
        }
    }
    
    /**
     * Delete session from DynamoDB
     */
    public function delete_session($customer_id) {
        try {
            $this->dynamodb_client->deleteItem([
                'TableName' => $this->table_name,
                'Key' => [
                    'session_key' => [
                        'S' => (string)$customer_id
                    ]
                ]
            ]);
            $this->write_log('Session deleted for key: ' . $customer_id, 'INFO');
        } catch (AwsException $e) {
            $this->write_log('Error deleting session from DynamoDB: ' . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Update session timestamp
     */
    public function update_session_timestamp($customer_id, $timestamp) {
        try {
            $this->dynamodb_client->updateItem([
                'TableName' => $this->table_name,
                'Key' => [
                    'session_key' => [
                        'S' => (string)$customer_id
                    ]
                ],
                'UpdateExpression' => 'SET session_expiry = :expiry',
                'ExpressionAttributeValues' => [
                    ':expiry' => [
                        'N' => (string)$timestamp
                    ]
                ]
            ]);
            $this->write_log('Session timestamp updated for key: ' . $customer_id, 'INFO');
        } catch (AwsException $e) {
            $this->write_log('Error updating session timestamp in DynamoDB: ' . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Cleanup expired sessions
     */
    public function cleanup_sessions() {
        try {
            $current_time = time();
            
            // Scan for expired sessions
            $result = $this->dynamodb_client->scan([
                'TableName' => $this->table_name,
                'FilterExpression' => 'session_expiry < :current_time',
                'ExpressionAttributeValues' => [
                    ':current_time' => [
                        'N' => (string)$current_time
                    ]
                ],
                'ProjectionExpression' => 'session_key'
            ]);
            
            // Delete expired sessions
            foreach ($result['Items'] as $item) {
                $this->delete_session($item['session_key']['S']);
            }
            $this->write_log('Cleanup of expired sessions completed', 'INFO');
        } catch (AwsException $e) {
            $this->write_log('Error cleaning up expired sessions: ' . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * Write a message to the log file
     */
    private function write_log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->log_file_path, $log_entry, FILE_APPEND);
    }
}

/**
 * Hook into WooCommerce session handler filter
 */
function wc_dynamodb_session_handler($session_class) {
    return 'WC_DynamoDB_Session_Handler';
}
add_filter('woocommerce_session_handler', 'wc_dynamodb_session_handler');

/**
 * Initialize table creation on plugin activation
 */
function wc_dynamodb_init_table() {
    $handler = new WC_DynamoDB_Session_Handler();
    $handler->create_table_if_not_exists();
}
register_activation_hook(__FILE__, 'wc_dynamodb_init_table');

/**
 * Scheduled cleanup of expired sessions
 */
function wc_dynamodb_cleanup_sessions() {
    $handler = new WC_DynamoDB_Session_Handler();
    $handler->cleanup_sessions();
}

// Schedule cleanup to run daily
if (!wp_next_scheduled('wc_dynamodb_cleanup_sessions')) {
    wp_schedule_event(time(), 'daily', 'wc_dynamodb_cleanup_sessions');
}
add_action('wc_dynamodb_cleanup_sessions', 'wc_dynamodb_cleanup_sessions');