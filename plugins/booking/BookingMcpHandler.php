<?php
/**
 * Booking — MCP AI Gateway Integration.
 *
 * Exposes Booking tools and scopes to Slate's Model Context Protocol (MCP) gateway,
 * allowing authorized AI assistants to query services/slots and book appointments.
 */

declare(strict_types=1);

class BookingMcpHandler {

    public static function register(): void {
        Hook::addFilter('slate_mcp_scopes',    [self::class, 'filterScopes']);
        Hook::addFilter('slate_mcp_tools',     [self::class, 'filterTools'], 10, 2);
        Hook::addFilter('slate_mcp_call_tool', [self::class, 'callTool'], 10, 4);
    }

    public static function filterScopes(array $scopes): array {
        $scopes['booking.read']  = 'View booking services, availability, and appointments';
        $scopes['booking.write'] = 'Create appointments and schedule bookings';
        return $scopes;
    }

    public static function filterTools(array $tools, array $context): array {
        $hasRead  = in_array('booking.read', (array)($context['scopes'] ?? []), true);
        $hasWrite = in_array('booking.write', (array)($context['scopes'] ?? []), true);

        if ($hasRead) {
            $tools[] = [
                'name'        => 'slate_booking_list_services',
                'description' => 'List all active booking services with duration, price, and buffer details.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => (object)[],
                ],
            ];
            $tools[] = [
                'name'        => 'slate_booking_list_slots',
                'description' => 'Compute available appointment slots for a service on a specified date.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'service_id'  => ['type' => 'integer', 'description' => 'ID of the service to check availability for'],
                        'date'        => ['type' => 'string',  'description' => 'Date in YYYY-MM-DD format'],
                        'provider_id' => ['type' => 'integer', 'description' => 'Optional provider ID (omit or 0 for any available provider)'],
                    ],
                    'required'   => ['service_id', 'date'],
                    'additionalProperties' => false,
                ],
            ];
        }

        if ($hasWrite) {
            $tools[] = [
                'name'        => 'slate_booking_create_appointment',
                'description' => 'Create and confirm a new booking appointment for a customer.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'service_id'     => ['type' => 'integer', 'description' => 'Service ID'],
                        'provider_id'    => ['type' => 'integer', 'description' => 'Optional provider ID (0 for auto-assign)'],
                        'date'           => ['type' => 'string',  'description' => 'Date in YYYY-MM-DD format'],
                        'slot'           => ['type' => 'string',  'description' => 'Start time slot in HH:MM format (24h)'],
                        'customer_name'  => ['type' => 'string',  'description' => 'Full name of the customer'],
                        'customer_email' => ['type' => 'string',  'description' => 'Customer email address'],
                        'customer_phone' => ['type' => 'string',  'description' => 'Optional customer phone number'],
                        'notes'          => ['type' => 'string',  'description' => 'Optional customer notes'],
                    ],
                    'required'   => ['service_id', 'date', 'slot', 'customer_name', 'customer_email'],
                    'additionalProperties' => false,
                ],
            ];
        }

        return $tools;
    }

    public static function callTool($result, string $name, array $args, array $context): mixed {
        if ($result !== null) return $result;

        if ($name === 'slate_booking_list_services') {
            if (!in_array('booking.read', (array)($context['scopes'] ?? []), true)) {
                throw new RuntimeException('This token does not grant booking read access.');
            }
            return ['services' => BookingAPI::getActiveServices()];
        }

        if ($name === 'slate_booking_list_slots') {
            if (!in_array('booking.read', (array)($context['scopes'] ?? []), true)) {
                throw new RuntimeException('This token does not grant booking read access.');
            }
            $serviceId  = (int)($args['service_id'] ?? 0);
            $date       = trim((string)($args['date'] ?? ''));
            $providerId = (int)($args['provider_id'] ?? 0);

            if ($serviceId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException("Missing or invalid 'service_id' or 'date' (YYYY-MM-DD).");
            }

            if ($providerId > 0) {
                $times = BookingAPI::computeAvailableSlots($serviceId, $providerId, $date);
                $slots = array_map(static fn($t) => ['time' => $t, 'provider_id' => $providerId], $times);
            } else {
                $providers = BookingAPI::getProvidersForService($serviceId);
                $slotsMap = [];
                foreach ($providers as $p) {
                    $pid = (int)$p['id'];
                    $times = BookingAPI::computeAvailableSlots($serviceId, $pid, $date);
                    foreach ($times as $t) {
                        if (!isset($slotsMap[$t])) {
                            $slotsMap[$t] = ['time' => $t, 'available_providers' => [], 'provider_id' => $pid];
                        }
                        $slotsMap[$t]['available_providers'][] = $pid;
                    }
                }
                ksort($slotsMap);
                $slots = array_values($slotsMap);
            }

            return ['service_id' => $serviceId, 'date' => $date, 'slots' => $slots];
        }

        if ($name === 'slate_booking_create_appointment') {
            if (!in_array('booking.write', (array)($context['scopes'] ?? []), true)) {
                throw new RuntimeException('This token does not grant booking write access.');
            }

            $serviceId  = (int)($args['service_id'] ?? 0);
            $providerId = (int)($args['provider_id'] ?? 0);
            $date       = trim((string)($args['date'] ?? ''));
            $slot       = trim((string)($args['slot'] ?? ''));
            $name       = trim((string)($args['customer_name'] ?? ''));
            $email      = trim((string)($args['customer_email'] ?? ''));
            $phone      = trim((string)($args['customer_phone'] ?? ''));
            $notes      = trim((string)($args['notes'] ?? ''));

            if ($serviceId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $slot)) {
                throw new InvalidArgumentException("Valid 'service_id', 'date' (YYYY-MM-DD), and 'slot' (HH:MM) are required.");
            }
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Valid 'customer_name' and 'customer_email' are required.");
            }

            $service = BookingAPI::getService($serviceId);
            if (!$service || empty($service['is_active'])) {
                throw new InvalidArgumentException('Service not found or inactive.');
            }

            if ($providerId <= 0) {
                $providers = BookingAPI::getProvidersForService($serviceId);
                foreach ($providers as $p) {
                    $pid = (int)$p['id'];
                    $times = BookingAPI::computeAvailableSlots($serviceId, $pid, $date);
                    if (in_array($slot, $times, true)) {
                        $providerId = $pid;
                        break;
                    }
                }
                if ($providerId <= 0 && !empty($providers)) {
                    $providerId = (int)$providers[0]['id'];
                }
            }

            $startsAt = $date . ' ' . $slot . ':00';
            $duration = (int)($service['duration_min'] ?? 30);
            $endsAt   = date('Y-m-d H:i:s', strtotime($startsAt) + ($duration * 60));

            $res = BookingAPI::createAppointment([
                'service_id'     => $serviceId,
                'provider_id'    => $providerId > 0 ? $providerId : null,
                'starts_at'      => $startsAt,
                'ends_at'        => $endsAt,
                'party_size'     => 1,
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'notes'          => $notes,
                'source'         => 'online',
            ]);

            if (empty($res['ok'])) {
                throw new RuntimeException($res['error'] ?? 'Booking creation failed.');
            }

            $apptId = (int)($res['id'] ?? 0);
            $appt = BookingAPI::getAppointment($apptId);

            return [
                'appointment_id' => $apptId,
                'ref'            => (string)($appt['ref'] ?? $res['ref'] ?? ''),
                'status'         => (string)($appt['status'] ?? $res['status'] ?? 'confirmed'),
                'service_name'   => (string)$service['name'],
                'starts_at'      => $startsAt,
                'ends_at'        => $endsAt,
            ];
        }

        return null;
    }
}
