# Database Setup Instructions

## Option 1: Using PHP Setup Script (Recommended)

1. Open your browser and go to:
   ```
   http://localhost/clinic1/setup_database.php
   ```

2. The script will automatically:
   - Create the database `clinic1_db`
   - Create all required tables
   - Add all columns for patient registration
   - Create indexes for better performance
   - Show you the table structure

## Option 2: Using phpMyAdmin

1. Open phpMyAdmin: `http://localhost/phpmyadmin`

2. Click on "SQL" tab

3. Copy and paste the contents of `database_setup.sql` file

4. Click "Go" to execute

## Option 3: Using MySQL Command Line

1. Open MySQL command line or terminal

2. Run:
   ```bash
   mysql -u root -p < database_setup.sql
   ```

## Database Structure

### Users Table
- **id** - Primary key
- **username** - Unique username
- **password** - Hashed password
- **full_name** - Patient's full name
- **role** - User role (admin, nurse, receptionist, patient)
- **email** - Email address
- **phone** - Mobile number
- **gender** - Male, Female, or Other
- **date_of_birth** - Date of birth
- **age** - Auto-computed age
- **civil_status** - Single, Married, Divorced, Widowed
- **address** - Street address
- **barangay** - Barangay
- **city** - City
- **emergency_contact_name** - Emergency contact name
- **emergency_contact_relationship** - Relationship to patient
- **emergency_contact_number** - Emergency contact number
- **created_at** - Registration timestamp

### Appointments Table
- **id** - Primary key
- **patient_id** - Foreign key to users table
- **doctor_id** - Doctor ID
- **appointment_date** - Appointment date
- **appointment_time** - Appointment time
- **status** - pending, confirmed, completed, cancelled
- **notes** - Additional notes
- **created_at** - Creation timestamp

## Default Users

After setting up the database, run `init_users.php` to create default user accounts:
- Admin: `admin` / `password123`
- Nurse: `nurse1` / `password123`
- Receptionist: `receptionist1` / `password123`
- Patient: `patient1` / `password123`

