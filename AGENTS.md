AGENTS.md - Taller Gómez
This repository contains a web application for a mechanical workshop management system. The system has both PHP frontend (public/ directory) and Node.js API (api/ directory).
Project Structure
- public/ - PHP frontend with web interface (Apache + PHP)
- api/ - Node.js REST API (Express.js)
- src/ - Shared PHP source code
- config/ - Configuration files
- database/ - Database schema and migrations
Key Commands
API Development
# Start API in development mode
cd api && npm run dev

# Run tests
cd api && npm test

# Start API in production mode  
cd api && npm start
Environment Setup
1. Copy config/local.example.php to config/local.php and configure database credentials
2. Set up MySQL database using schema from database/schema.sql
3. Apply migrations from database/ directory
Database Information
The database uses MySQL 8.0 with InnoDB engine and utf8mb4 charset.
- Main tables: usuarios, vehiculos, turnos, trabajos, diagnosticos, repuestos, presupuestos, items_presupuesto
- API URL is configured via environment variable API_URL (defaults to http://localhost:3000)
- Database credentials are read from environment variables or config/local.php
Authentication Flow
- PHP frontend uses sessions for authentication
- API routes are protected by middleware and require proper authorization headers
- Different user roles: cliente, mecanico, admin with different access levels
Key Endpoints
API endpoints:
- /api/turnos - Manage appointments
- /api/trabajos - Manage work orders  
- /api/dashboard - Dashboard data
- /api/presupuestos - Manage quotes and budgets
Important Notes
1. The frontend runs on Apache with mod_rewrite enabled for clean URLs
2. API uses helmet.js for security headers and express-rate-limit for rate limiting
3. CORS is configured via CORS_ORIGENES environment variable (defaults to http://localhost:8000)
4. The system requires both PHP frontend and Node.js API to be running for full functionality
5. Database migrations must be applied in order: schema.sql, then 003_tokens_api.sql, then 004_intentos_logins.sql
Testing
- API tests are written using Node.js built-in test runner
- Test file: api/test/fechas.test.js (contains date validation utilities)
This AGENTS.md file captures the essential information an agent would need to understand and work with this codebase effectively. It includes:
1. Project structure - Helps understand how files are organized
2. Key commands - Essential development and deployment instructions 
3. Database setup - Critical for understanding how to initialize the system
4. Authentication flow - Important for understanding security aspects
5. API endpoints - Key interfaces for working with the system
6. Important notes - Specific implementation details that aren't obvious from file names alone
7. Testing information - How to verify changes work correctly
The file is compact and focused on high-signal information that would otherwise be hard to discover through exploration alone, following your guidelines about only including what an agent would likely miss without help.