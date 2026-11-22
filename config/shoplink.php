<?php

/*
|--------------------------------------------------------------------------
| ShopLink Supermarket Corporation - Customer Management configuration
|--------------------------------------------------------------------------
|
| Reference lists used by the Customer Management Module. They are kept
| here (instead of being hardcoded in controllers or in React) so that the
| business can adjust them without code changes elsewhere. The frontend
| reads them from GET /api/customer-management/options.
|
| Loyalty earning rates are NOT defined here: they are stored in the
| loyalty_settings table and maintained by authorized administrators.
|
*/

return [

    'company' => [
        'name' => 'ShopLink Supermarket Corporation',
        'location' => 'Calapan City, Oriental Mindoro, Philippines',
        'currency' => 'PHP',
    ],

    'customer_statuses' => [
        'Pending Verification',
        'Verified',
        'Active',
        'VIP',
        'Inactive',
        'Suspended',
        'Blocked',
        'Archived',
    ],

    // Statuses in which a customer may transact, earn and redeem points.
    'transacting_statuses' => ['Verified', 'Active', 'VIP'],

    'customer_types' => [
        'Individual',
        'Senior Citizen',
        'PWD',
        'Business',
    ],

    'contact_methods' => ['SMS', 'Email', 'Phone Call', 'None'],

    'registration_channels' => ['In-store', 'Website', 'Email', 'Phone'],

    'verification_methods' => [
        'Government-issued ID',
        'Company ID',
        'Senior Citizen ID',
        'PWD ID',
        'Email confirmation',
        'Phone confirmation',
    ],

    'feedback_types' => [
        'General Feedback',
        'Suggestion',
        'Complaint',
        'Product Concern',
        'Service Concern',
    ],

    // Types that follow the complaint assignment/resolution workflow.
    'complaint_types' => ['Complaint', 'Product Concern', 'Service Concern'],

    'feedback_categories' => [
        'Product Quality',
        'Pricing',
        'Product Availability',
        'Staff Behavior',
        'Checkout / Cashier',
        'Store Cleanliness',
        'Loyalty Program',
        'Delivery',
        'Website',
        'Other',
    ],

    'feedback_channels' => ['In-store', 'Website', 'Email', 'Phone', 'Social Media'],

    'feedback_statuses' => [
        'Pending',
        'Under Review',
        'Assigned',
        'In Progress',
        'Resolved',
        'Closed',
    ],

    'feedback_priorities' => ['Low', 'Medium', 'High', 'Urgent'],

    'departments' => [
        'Customer Service',
        'Store Operations',
        'Merchandising',
        'Inventory / Warehouse',
        'Sales',
        'Marketing',
        'Management',
        'IT',
    ],

    // Order status at which a sale counts as completed for loyalty earning.
    'loyalty_earning_order_status' => 'Delivered',

    'notifications' => [
        // Master switch. When false, notifications are recorded as "skipped".
        'enabled' => env('SHOPLINK_NOTIFICATIONS_ENABLED', true),
    ],

    // Frontend base URL, used in password reset links.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
];
