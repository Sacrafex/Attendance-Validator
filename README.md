# Attendance Validator - (Version 2 will be released soon!)
### Made for Team Daedalus 2839

A secure, feature-rich PHP-based attendance tracking system designed for team management with comprehensive statistics, absence scheduling, and administrative controls.

## Features

### Secure Authentication
- **PIN-only login system** - Quick and secure access without username selection
- **Unique PIN enforcement** - Each member must have a unique 4-6 digit PIN
- **Admin dashboard** with password protection
- **Device authentication** - Initial setup requires authentication code
- **Auto-fill disabled** - Enhanced security for PIN entry fields

### Member Statistics & Analytics
- **Individual member statistics** including:
  - Total attendance sessions
  - Total hours worked
  - Average session time
  - Attendance rate percentage
  - Late arrival tracking (>1 hour after session start)
  - Last attendance date
- **Team summary dashboard** with:
  - Total members and sessions
  - Team-wide hours logged
  - Average attendance rates
  - Active members count

### Session Management
- **Active session tracking** - Real-time session status and uptime
- **Automatic clock-out** - End sessions and clock out all members
- **Session history** - Complete record of all past sessions
- **Late arrival detection** - Track members arriving >1 hour after session start

### Administrative Controls
- **Member management** - Add, update, and remove team members
- **Statistics management** - Clear individual member stats (with password confirmation)
- **User account deletion** - Permanently remove users and their data
- **Session data cleanup** - Remove historical session records
- **Daily reporting** - Generate and send team reports

### User Interface
- **Responsive design** - Works on desktop and mobile devices
- **Modern styling** - Clean, professional appearance
- **Intuitive navigation** - Easy-to-use interface for all users
- **Real-time feedback** - Success/error messages for all actions
- **Visual statistics** - Clear presentation of data and metrics

## Requirements

- **PHP 7.0+** with SQLite3 extension
- **Web server** (Apache, Nginx, or built-in PHP server)
- **Modern web browser** with JavaScript enabled

## Installation

1. **Clone the repository:**
   ```bash
   git clone https://github.com/[your-username]/Attendance-Validator.git
   cd Attendance-Validator
   ```

2. **Configure authentication:**
   - Open `index.php`
   - Change the `$auth_code` variable (line 4) to a secure value
   - Change the `$adminPassword` variable (line 69) to a secure admin password

3. **Set up web server:**
   
   **Option A: Using PHP built-in server (for testing)**
   ```bash
   php -S localhost:8000
   ```
   
   **Option B: Using Apache/Nginx**
   - Copy files to your web root directory
   - Ensure PHP has write permissions for the database file

4. **Access the application:**
   - Navigate to your server URL
   - Enter the authentication code you set in step 2
   - The SQLite database will be created automatically

## Configuration

### Security Settings
- **Authentication Code**: Change `$auth_code` in `index.php` (line 4)
- **Admin Password**: Change `$adminPassword` in `index.php` (line 69)
- **Cookie Settings**: Modify cookie expiration times if needed

### Database
- The system uses SQLite and creates `attendance.db` automatically
- Database includes tables for: members, sessions, attendance, future_absences

## Usage

### For Team Members

1. **Login:**
   - Visit the application URL
   - Enter your unique PIN (no username required) / Scan Barcode

2. **Clock In/Out:**
   - Scan Barcode to Clock both In and Out.
   - View current session status and uptime

### For Administrators

1. **Access Admin Panel:**
   - Press `Ctrl + Alt + D` for quick access, or
   - Navigate to `?stage=admin_login`
   - Enter admin password

2. **Session Management:**
   - Start/end lab sessions
   - View session history
   - Monitor active participants

3. **Member Management:**
   - Add new team members with unique PINs
   - Update member information
   - Remove members (with data cleanup options)

4. **Statistics & Reports:**
   - View comprehensive member statistics
   - Monitor attendance rates and patterns
   - Generate daily reports
   - Track late arrivals

5. **Data Management:**
   - Clear individual member statistics
   - Delete user accounts permanently

## Database Schema

### Tables
- **members**: User accounts with unique PINs
- **sessions**: Lab session records
- **attendance**: Clock in/out records
- **future_absences**: Scheduled absence records

### Key Features
- Foreign key relationships for data integrity
- Automatic timestamp generation
- Unique constraints for security

## Security Features

- **PIN-based authentication** prevents unauthorized access
- **Unique PIN enforcement** ensures no duplicate access codes
- **Admin password protection** for sensitive operations
- **Device authentication** for initial setup
- **Disabled auto-fill** prevents browser password managers from interfering
- **SQL injection protection** through prepared statements

## Quick Access

- **Admin Panel**: Scan Admin Barcode
- **User Login**: Scan User Barcode
- **Session Status**: Displayed on all pages

## Statistics Tracked

- Individual attendance sessions
- Total hours worked per member
- Average session duration
- Attendance rate percentages
- Late arrival incidents
- Team-wide metrics and summaries

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

This project is open source. Feel free to use and modify for your team's needs.

## Support

For issues or questions:
1. Check the code comments for implementation details
2. Review the database schema for data structure
3. Test configuration changes in a development environment

---

**Note**: This system is designed for team attendance tracking and includes features for both regular members and administrators. Ensure proper security measures are in place before deploying to a production environment.
