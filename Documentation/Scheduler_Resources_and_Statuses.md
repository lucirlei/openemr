# Scheduler Resources and Appointment Statuses

OpenEMR now supports scheduling appointments against multiple resources (equipment and rooms) and exposes a customizable set of appointment statuses for the Patient Flow Board.

This guide summarizes how to configure these enhancements so that clinics can coordinate personnel, rooms, and equipment more reliably.

## Scheduler Resources

Scheduler resources represent physical rooms, shared equipment, or any other asset that must be reserved alongside an appointment.

### Creating and Managing Resources

1. Navigate to **Administration ➜ Clinic ➜ Scheduler Resources**.
2. Add each resource with a descriptive name, type (`room`, `equipment`, or `generic`), optional color, and home facility.
3. Deactivate resources that are no longer in use instead of deleting them to preserve historical links.

The application stores resources in the new `scheduler_resources` table and links them to appointments through `event_resource_link`.

### Using Resources in the Calendar

* When creating or editing an appointment in the main calendar, select the desired **Provider**, **Resource(s)**, and **Room**.
* The appointment form automatically checks for conflicts across the chosen provider and resource list.
* If any resource is unavailable, the form displays a warning with the overlapping appointment details so the user can choose different times or resources.

Appointments now record their room assignment via `room_resource_id` and store all other selected resources as `resources[]` within the request payload processed by `AppointmentService`.

### Reporting on Resources

Reports that rely on `library/appointments.inc.php`, such as the Appointment Report and custom exports, now include aggregated columns for `resource_list` and `room_resource_list`. Use the updated filters to limit results to specific resource IDs.

## Custom Appointment Statuses

The Patient Tracker and calendar use the `list_options` table to control appointment statuses.

### Adding Status Entries

1. Go to **Administration ➜ Lists** and open the `apptstat` list.
2. Add new items such as `Aguardando avaliação` or `Em procedimento` with appropriate titles and optional colors.
3. Assign a sort order that matches your workflow.

The upgrade scripts seed these example statuses, but you can extend the list at any time. Newly added statuses become available in the calendar, patient tracker, and appointment reports without additional code changes.

### Workflow Tips

* Configure the Patient Flow Board columns to match the statuses you rely on most.
* Train staff to update the status as patients move through the clinic so downstream reports remain accurate.
* Use custom colors to highlight critical steps (e.g., `Em procedimento` in orange).

---

For additional technical details, review the updated PHP service layer (`src/Services/AppointmentService.php`) and SQL definitions located in `sql/database.sql` and `sql/7_0_4-to-7_0_5_upgrade.sql`.
