-- Booking+ plugin — uninstall. Drops every bookingplus_* table.
-- Core `booking_*` tables and the practitioner's appointment history
-- are left untouched (Booking+ only owns the extras layer).
DROP TABLE IF EXISTS `bookingplus_appointment_meta`;
DROP TABLE IF EXISTS `bookingplus_slot_restrictions`;
DROP TABLE IF EXISTS `bookingplus_service_config`;
