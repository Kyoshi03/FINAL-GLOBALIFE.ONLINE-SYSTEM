# Globalife System Flowchart

This flowchart documents the current patient, doctor, and admin workflow implemented in the system.

## End-to-End Workflow

```mermaid
flowchart TD
    Start([Start]) --> Role{User side}

    subgraph PATIENT[Patient side]
        P1[Register or log in]
        P2{Contact verified?}
        P3[Verify email or phone]
        P4[Open Book Appointment]
        P5[Choose appointment type]
        P6{Consultation?}
        P7[Choose doctor and specialty]
        P8[Choose laboratory or other service]
        P9[Choose date and time]
        P10{Available and within cutoff?}
        P11[Choose another date or time]
        P12[Confirm booking]
        P13[Appointment created as pending]
        P14[View appointments, history, queue, and notifications]
        P15{Reschedule or cancel?}
        P16[Reschedule pending appointment directly]
        P17[Submit reschedule request for confirmed appointment]
        P18[Cancel pending or confirmed appointment if queue has not started]
        P19[Attend visit and receive completed or cancelled status]

        P1 --> P2
        P2 -- No --> P3 --> P4
        P2 -- Yes --> P4
        P4 --> P5 --> P6
        P6 -- Yes --> P7 --> P9
        P6 -- No --> P8 --> P9
        P9 --> P10
        P10 -- No --> P11 --> P9
        P10 -- Yes --> P12 --> P13 --> P14
        P14 --> P15
        P15 -- Pending reschedule --> P16 --> P14
        P15 -- Confirmed reschedule --> P17 --> AR1
        P15 -- Cancel --> P18 --> P14
        P15 -- No change --> P19
    end

    subgraph DOCTOR[Doctor side]
        D1[Open doctor dashboard]
        D2[Review assigned consultation appointments]
        D3{Pending consultation decision}
        D4[Confirm consultation]
        D5[Cancel consultation]
        D6[Online queue row created and patient notified]
        D7[Conduct consultation]
        D8[Add medical notes]
        D9[Mark appointment completed]
        D10[View consultation report]
        D11[Select weekly, monthly, quarterly, or custom range]
        D12[Graph and total update for completed consultations]

        D1 --> D2 --> D3
        D3 -- Confirm --> D4 --> D6 --> D7 --> D8 --> D9 --> D10 --> D11 --> D12
        D3 -- Cancel --> D5 --> D10
    end

    subgraph ADMIN[Admin side]
        A1[Open admin dashboard]
        A2[Review pending appointments]
        A3{Appointment type}
        A4[Manage laboratory or other appointment]
        A5{Admin decision}
        A6[Confirm appointment]
        A7[Cancel appointment]
        A8[Online queue row created and patient notified]
        A9[Complete confirmed non-consultation appointment]
        A10[Review confirmed reschedule request]
        A11{Approve request?}
        A12[Apply new schedule]
        A13[Reject request]
        A14[Manage accounts, doctors, services, schedules, and queue]
        A15[Open sales/report filters]
        A16[Generate graph, detailed sales, PDF, or Excel]

        A1 --> A2 --> A3
        A3 -- Laboratory or other --> A4 --> A5
        A5 -- Confirm --> A6 --> A8 --> A9
        A5 -- Cancel --> A7
        A2 --> A10 --> A11
        A11 -- Yes --> A12 --> A2
        A11 -- No --> A13 --> A2
        A1 --> A14
        A1 --> A15 --> A16
    end

    Role -- Patient --> P1
    Role -- Doctor --> D1
    Role -- Admin --> A1

    P13 -. pending consultation .-> D2
    P13 -. pending laboratory or other .-> A2
    D4 -. confirmation notification .-> P14
    D5 -. cancellation notification .-> P14
    A6 -. confirmation notification .-> P14
    A7 -. cancellation notification .-> P14
    A8 -. queue notification .-> P14
    P17 --> AR1[Admin receives reschedule request]
    D9 -. completed consultation appears in report .-> D10
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
