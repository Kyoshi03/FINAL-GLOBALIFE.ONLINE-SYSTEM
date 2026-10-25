# Globalife System Flowchart

This flowchart documents the current patient, doctor, and admin workflow implemented in the system.

## 1. Admin Side

```mermaid
flowchart TD
    A1([Admin logs in]) --> A2[Open admin dashboard]
    A2 --> A3[Review pending appointments]
    A3 --> A4{Appointment type}
    A4 -- Laboratory or other --> A5[Review appointment details]
    A5 --> A6{Admin decision}
    A6 -- Confirm --> A7[Set status to confirmed]
    A7 --> A8[Create online queue row and notify patient]
    A8 --> A9[Complete appointment after service]
    A6 -- Cancel --> A10[Set status to cancelled]
    A3 --> A11[Review confirmed reschedule requests]
    A11 --> A12{Approve request?}
    A12 -- Yes --> A13[Apply new schedule]
    A12 -- No --> A14[Reject request]
    A2 --> A15[Manage accounts, doctors, services, and schedules]
    A2 --> A16[Manage queue and notifications]
    A2 --> A17[Open sales report]
    A17 --> A18[Select period, date range, and filters]
    A18 --> A19[View graph and detailed sales]
    A19 --> A20[Print or download PDF/XLSX]
```

## 2. Doctor Side

```mermaid
flowchart TD
    D1([Doctor logs in]) --> D2[Open doctor dashboard]
    D2 --> D3[Review assigned consultation appointments]
    D3 --> D4{Pending consultation decision}
    D4 -- Confirm --> D5[Set status to confirmed]
    D5 --> D6[Create online queue row and notify patient]
    D6 --> D7[Conduct consultation]
    D7 --> D8[Add medical notes]
    D8 --> D9[Set status to completed]
    D4 -- Cancel --> D10[Set status to cancelled]
    D2 --> D11[View patients and appointment history]
    D2 --> D12[Open consultation report]
    D12 --> D13[Select weekly, monthly, quarterly, or custom range]
    D13 --> D14[Update graph and completed consultation total]
```

## 3. Patient Side

```mermaid
flowchart TD
    P1([Patient registers or logs in]) --> P2{Contact verified?}
    P2 -- No --> P3[Verify email or phone]
    P3 --> P4[Open Book Appointment]
    P2 -- Yes --> P4
    P4 --> P5[Choose appointment type]
    P5 --> P6{Consultation?}
    P6 -- Yes --> P7[Choose doctor and specialty]
    P6 -- No --> P8[Choose laboratory or other service]
    P7 --> P9[Choose date and time]
    P8 --> P9
    P9 --> P10{Available and within cutoff?}
    P10 -- No --> P11[Choose another date or time]
    P11 --> P9
    P10 -- Yes --> P12[Confirm booking]
    P12 --> P13[Appointment created as pending]
    P13 --> P14[View appointments, history, queue, and notifications]
    P14 --> P15{Reschedule or cancel?}
    P15 -- Pending reschedule --> P16[Change schedule directly; remains pending]
    P16 --> P14
    P15 -- Confirmed reschedule --> P17[Submit reschedule request for admin review]
    P17 --> P14
    P15 -- Cancel --> P18[Cancel if queue has not started]
    P18 --> P14
    P15 -- No change --> P19[Attend visit and receive completed or cancelled status]
```

## Role Handoffs

```mermaid
flowchart LR
    PENDING([Patient booking: pending]) -. Consultation .-> DOCTOR[Doctor reviews]
    PENDING -. Laboratory or other .-> ADMIN[Admin reviews]
    DOCTOR -->|Confirm or cancel| PATIENT[Patient receives status notification]
    ADMIN -->|Confirm, cancel, or reschedule decision| PATIENT
    DOCTOR -->|Complete consultation| REPORT[Doctor consultation report]
    ADMIN -->|Sales filters and exports| SALES[Admin sales report]
```

## Appointment Status Flow

```mermaid
flowchart LR
    Pending([Pending]) -->|Doctor: consultation confirm| Confirmed([Confirmed])
    Pending -->|Admin: laboratory or other confirm| Confirmed
    Pending -->|Patient, doctor, or admin cancel when allowed| Cancelled([Cancelled])
    Confirmed -->|Doctor: consultation complete| Completed([Completed])
    Confirmed -->|Admin: laboratory or other complete| Completed
    Confirmed -->|Patient, doctor, or admin cancel when allowed| Cancelled
    Pending -->|Patient reschedule| Pending
    Confirmed -->|Patient submits reschedule request| Review([Admin review])
    Review -->|Approve| Confirmed
    Review -->|Reject| Confirmed
```

## Report and Export Flow

```mermaid
flowchart TD
    R1[Doctor or admin opens report] --> R2[Choose period or custom date range]
    R2 --> R3[Apply active filters and date range]
    R3 --> R4[Load all matching records]
    R4 --> R5[Calculate totals and graph points]
    R5 --> R6[Render report on screen]
    R6 --> R7{Export action}
    R7 -- Print --> R8[Printable report view]
    R7 -- Download PDF --> R9[PDF file]
    R7 -- Download Excel --> R10[Editable XLSX workbook]
    R10 --> R11[Detailed Sales table and native chart when available]
```

## Rules Captured

- New bookings start as `pending`.
- Doctors control the confirmation, cancellation, and completion of their own consultation appointments.
- Admin controls laboratory and other appointment workflows, account/master-data management, queue management, and reports.
- A pending patient reschedule remains pending and can be changed directly.
- A confirmed patient reschedule becomes a request for admin review.
- Completed consultations feed the doctor's consultation report.
- Patient, doctor, and admin views use their own permitted records and actions.
