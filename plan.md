# Bedrock

## Laravel Modular Application & Marketplace System

### 1. Project Vision

**Bedrock** হবে Laravel-এর জন্য একটি advanced, plug-and-play modular application system।

মূল লক্ষ্য হলো:

* একই ধরনের functionality বারবার নতুন করে তৈরি না করা
* Application-কে independent modules-এ ভাগ করা
* Module install, uninstall, enable ও disable করা
* Backend এবং frontend—দুটোকেই module-এর অংশ হিসেবে পরিচালনা করা
* একটি central Core Module-এর মাধ্যমে পুরো modular ecosystem পরিচালনা করা
* Marketplace-এর মাধ্যমে module discover, download, install ও license করা
* Runtime-এ module dynamically load করার capability রাখা
* ভবিষ্যতে multi-tenant, licensing, dependency management এবং security-এর মতো advanced feature support করা

System-এর foundation হবে:

**Laravel 12 + React + Inertia.js + TypeScript + Tailwind CSS + shadcn/ui + Vite**

---

# 2. Overall Architecture

Bedrock মূলত তিনটি স্তরে কাজ করবে:

### Host Application

যে Laravel application-এ Bedrock ব্যবহার করা হবে।

এর মধ্যে থাকবে:

* Laravel application
* Inertia.js
* React
* TypeScript
* Tailwind CSS
* shadcn/ui
* Vite
* Bedrock Core
* Installed Feature Modules

### Core Module

Core Module হবে পুরো modular system-এর foundation।

এটি কোনো সাধারণ feature module নয়। বরং এটি module ecosystem-এর infrastructure layer।

এর দায়িত্ব হবে:

* Module discovery
* Module registration
* Module lifecycle
* Installation
* Uninstallation
* Enable/disable
* Dependency resolution
* Module state management
* Module settings
* Frontend module registry
* Asset management
* Module permissions
* Menu/navigation registration
* Marketplace communication
* Licensing
* Module scaffolding
* Security
* Module events

### Marketplace Service

Marketplace হবে আলাদা central service/API।

এখানে থাকবে:

* Module catalog
* Module versions
* Module metadata
* Module downloads
* Licensing
* Purchases
* Changelog
* Version management
* License verification
* Signed downloads

Host application marketplace-এর সাথে communicate করে প্রয়োজনীয় module install করবে।

---

# 3. Core Philosophy

Bedrock-এর মূল architecture হবে:

**Core → Modules → Marketplace**

প্রতিটি feature module হবে self-contained এবং যতটা সম্ভব independent।

একটি module-এর মধ্যে থাকবে:

* Backend logic
* Controllers
* Models
* Routes
* Database migrations
* Configuration
* Permissions
* Settings
* Frontend pages
* Frontend components
* Navigation/menu definitions
* Module metadata
* Dependencies
* Assets

অর্থাৎ একটি module শুধুমাত্র backend package হবে না; এটি হবে একটি complete application feature unit।

---

# 4. Core Module

Core Module হবে Bedrock-এর সবচেয়ে গুরুত্বপূর্ণ অংশ।

এর মধ্যে থাকবে module ecosystem পরিচালনার সব foundation functionality।

### Core Responsibilities

* Module Manager
* Module Installer
* Module Uninstaller
* Module Registry
* Dependency Resolver
* Module Downloader
* Asset Linker
* Module Discovery
* Module Lifecycle
* Module Settings
* Module Permissions
* Module Events
* Module Middleware
* Module Commands
* Frontend Module Registry
* Inertia Page Resolver
* Module Boundary
* Marketplace Client
* Licensing system
* Module generator
* Module publishing
* Security validation

Core Module-এর উপর অন্য সব feature module নির্ভর করতে পারবে।

---

# 5. Module Structure

প্রতিটি module হবে একটি independent unit।

একটি module-এর মধ্যে সাধারণত থাকবে:

* Module manifest
* Service Provider
* Backend source
* Controllers
* Models
* Routes
* Database migrations
* Configurations
* Seeders
* Frontend pages
* Frontend components
* Language files
* Module settings
* Module assets
* Frontend entry
* Vite build configuration

Module structure এমন হতে হবে যাতে module-টি অন্য application-এও একই Bedrock ecosystem-এর মাধ্যমে install করা যায়।

---

# 6. Module Manifest

প্রতিটি module-এর একটি central manifest থাকবে।

Manifest module সম্পর্কে machine-readable information প্রদান করবে।

এতে থাকবে:

### Identity

* Module key
* Module name
* Version
* Description
* Provider

### Dependencies

* Required modules
* Required versions
* Required core version
* External module dependencies

### Permissions

Module-এর নিজস্ব permissions-এর তালিকা।

যেমন:

* Create
* Edit
* Delete
* Publish
* View

### Settings

Module-এর configuration/settings schema কোথায় আছে তা manifest থেকে জানা যাবে।

এর মাধ্যমে Core Module ভবিষ্যতে settings UI generate করতে পারবে।

### Database

Manifest থেকে জানা যাবে:

* Migration আছে কিনা
* Seeder আছে কিনা
* কী ধরনের initialization প্রয়োজন

### Frontend

Frontend সম্পর্কিত information থাকবে:

* Pages location
* Module entry
* Navigation/menu items
* Route information
* Required permissions

### Assets

Module-এর publishable assets এবং অন্যান্য resource-এর metadata থাকবে।

### Licensing

Module-এর license requirement এবং license type নির্ধারণ করা যাবে।

---

# 7. Module Lifecycle

একটি module-এর lifecycle পরিষ্কারভাবে defined থাকবে।

প্রধান lifecycle states:

**Discovered → Installed → Enabled → Disabled → Uninstalled**

এছাড়াও installation failure-এর মতো state থাকতে পারে।

### Installation

Module install করার সময় system:

1. Module manifest validate করবে
2. Module compatibility যাচাই করবে
3. Dependencies resolve করবে
4. Dependency order নির্ধারণ করবে
5. Module files install করবে
6. Composer dependency compatibility যাচাই করবে
7. Database migrations চালাবে
8. Required seeders চালাবে
9. Configuration ও assets publish করবে
10. Frontend assets প্রস্তুত করবে
11. Module registry-তে register করবে
12. Installation event dispatch করবে
13. সফল হলে module active করার জন্য প্রস্তুত করবে

### Enable

Enable করলে:

* Module active হবে
* Routes available হবে
* Services boot হবে
* Permissions available হবে
* Menu items available হবে
* Frontend module accessible হবে

### Disable

Disable করলে:

* Module-এর functionality বন্ধ হবে
* Routes inactive হবে
* Menu items hide হবে
* Frontend module ব্যবহার করা যাবে না

তবে module-এর files এবং database data থাকবে।

### Uninstall

Uninstall-এর আগে module disable হবে।

তারপর:

* Module resources remove করা
* Permissions/settings remove বা archive করা
* প্রয়োজন অনুযায়ী migration rollback করা
* Registry থেকে module remove করা

Hard uninstall-এর ক্ষেত্রে module-এর data-ও permanently remove করার ব্যবস্থা থাকবে।

---

# 8. Dependency Management

Bedrock-এর নিজস্ব dependency resolver থাকবে।

Module dependency Composer dependency-এর মতো version constraint ব্যবহার করতে পারবে।

উদাহরণ:

* Core-এর নির্দিষ্ট version প্রয়োজন
* অন্য module-এর নির্দিষ্ট version প্রয়োজন
* একাধিক dependency chain থাকতে পারে

System:

* Dependency graph তৈরি করবে
* Installation order নির্ধারণ করবে
* Version compatibility যাচাই করবে
* Circular dependency detect করবে
* Missing dependency detect করবে
* Incompatible dependency detect করবে

Dependency ছাড়া কোনো module install করা যাবে না।

---

# 9. Database Registry

Core Module একটি central module registry রাখবে।

এখানে থাকবে:

* Module key
* Module name
* Installed version
* Provider
* Dependencies
* Marketplace metadata
* Current status
* Installation timestamp
* অন্যান্য module metadata

এর মাধ্যমে runtime-এ system জানতে পারবে:

* কোন module installed
* কোন module enabled
* কোন module disabled
* কোন module failed
* কোন version বর্তমানে installed

---

# 10. Module Settings

প্রতিটি module নিজের settings define করতে পারবে।

Core Module settings-এর জন্য central infrastructure প্রদান করবে।

Settings system-এর মাধ্যমে:

* Module-specific configuration
* Admin-configurable values
* Structured settings
* Dynamic settings UI
* Default values
* Validation

পরিচালনা করা যাবে।

Module manifest/settings schema ব্যবহার করে ভবিষ্যতে UI automatically generate করার সুযোগ থাকবে।

---

# 11. Permissions & Authorization

প্রতিটি module নিজের permissions define করতে পারবে।

Core Module সেই permissions application-এর authorization system-এর সাথে integrate করবে।

এর ফলে module install হওয়ার সময় তার permissions register হবে এবং module uninstall হলে প্রয়োজন অনুযায়ী permissions remove/archive করা যাবে।

Module-এর:

* Menu
* Pages
* Actions
* Features

permission-aware হতে পারবে।

---

# 12. Navigation & Menu System

Module নিজস্ব navigation/menu item register করতে পারবে।

একটি module তার:

* Label
* Icon
* Route
* Required permission

define করবে।

Core Module সমস্ত installed এবং enabled module-এর menu collect করে host application's navigation system-এ expose করবে।

ফলে নতুন module install করলে application-এর navigation automatically extend হবে।

---

# 13. Backend Runtime Architecture

Runtime-এ Core Module প্রথমে installed module-গুলো discover করবে।

তারপর registry অনুযায়ী enabled module-এর service provider এবং অন্যান্য প্রয়োজনীয় infrastructure boot করবে।

এর ফলে:

* Disabled module application runtime-এ active হবে না
* Enabled module-এর routes/services available হবে
* Module state database-driven হবে
* Module lifecycle centrally controlled হবে

---

# 14. Frontend Architecture

Frontend হবে Bedrock-এর সবচেয়ে advanced অংশগুলোর একটি।

কারণ Vite সাধারণত build-time bundler হিসেবে কাজ করে, কিন্তু Bedrock-এর লক্ষ্য হবে module-কে application-এর সাথে tightly coupled না করে dynamically load করা।

Frontend architecture-এর লক্ষ্য:

**Module install → Module build → Module entry available → Runtime lazy loading**

---

# 15. Module-specific Frontend Build

প্রতিটি module নিজের frontend build system রাখবে।

Module-এর frontend আলাদা build artifact হিসেবে compile হবে।

Host application-এর shared dependencies যেমন:

* React
* React DOM
* Inertia

প্রয়োজনে host application থেকে shared থাকবে, যাতে প্রতিটি module একই dependency-এর duplicate bundle না করে।

এর ফলে module-এর frontend bundle ছোট এবং independent রাখা যাবে।

---

# 16. Runtime Module Registry

Core frontend layer-এ একটি Module Registry থাকবে।

এর দায়িত্ব:

* Module load করা
* Already loaded module cache করা
* Module entry resolve করা
* Module pages resolve করা
* Module exports manage করা

কোনো module প্রথমবার প্রয়োজন হলে সেটি lazy-load হবে।

---

# 17. Inertia Integration

Backend থেকে module page নির্দিষ্ট করা যাবে।

Page resolution system module-aware হবে।

অর্থাৎ application বুঝতে পারবে:

* এটি Core page
* নাকি কোনো module-এর page

Module page হলে:

1. Module identify করা হবে
2. Module manifest পাওয়া হবে
3. Module frontend entry load হবে
4. প্রয়োজনীয় page resolve হবে
5. Inertia page render করবে

এভাবে module frontend host application's মূল source tree-এর সাথে permanently coupled থাকবে না।

---

# 18. Frontend Dynamic Loading

Bedrock-এর target architecture হবে runtime dynamic loading।

এর ফলে:

* Module install করার জন্য মূল application-এর frontend source পরিবর্তন করতে হবে না
* Main application-এর full frontend bundle rebuild করার প্রয়োজন থাকবে না
* Module নিজস্ব compiled asset নিয়ে আসবে
* প্রয়োজনের সময় module lazy-load হবে
* Unused module initial bundle-এর অংশ হবে না

এটি Bedrock-এর plug-and-play architecture-এর অন্যতম গুরুত্বপূর্ণ অংশ।

---

# 19. Frontend Build Pipeline

Module install হওয়ার সময় তার frontend build process পরিচালিত হবে।

Pipeline:

**Download → Extract → Validate → Install → Build Frontend → Publish Module Assets → Register Module → Enable**

Frontend build সম্পূর্ণ না হওয়া পর্যন্ত module enabled হবে না।

Build process background queue-এর মাধ্যমে পরিচালনা করা যাবে।

---

# 20. Queue & Installation Progress

Module installation asynchronous হতে পারবে।

Admin panel থেকে installation progress দেখা যাবে।

Progress stages হতে পারে:

* Downloading
* Validating
* Extracting
* Resolving dependencies
* Installing
* Migrating
* Seeding
* Publishing assets
* Building frontend
* Registering
* Finalizing
* Completed
* Failed

এতে administrator real-time বা polling-based progress দেখতে পারবে।

---

# 21. Marketplace

Bedrock-এর একটি central Marketplace ecosystem থাকবে।

Marketplace হবে আলাদা service/API।

এখানে module publisher এবং consumer-এর মধ্যে distribution layer হিসেবে কাজ করবে।

Marketplace-এ থাকবে:

* Module catalog
* Module details
* Module versions
* Module descriptions
* Screenshots/thumbnails
* Documentation
* Changelog
* Pricing
* Licensing
* Download management
* Compatibility information
* Release management

---

# 22. Marketplace Integration

Host application-এর Core Module Marketplace-এর সাথে communicate করবে।

Host application থেকে administrator:

* Module browse করতে পারবে
* Module details দেখতে পারবে
* Version দেখতে পারবে
* Module install করতে পারবে
* Update করতে পারবে
* License verify করতে পারবে

Marketplace থেকে module download করার পর Core Module installation lifecycle পরিচালনা করবে।

---

# 23. Licensing System

Marketplace-distributed module-এর জন্য licensing support থাকবে।

License system:

* License key
* Domain/application identity
* Module identity
* License status
* Activation
* Verification
* Expiration
* Periodic re-verification

support করবে।

প্রতিটি request-এ Marketplace ping না করে cached verification এবং background periodic verification ব্যবহার করা হবে।

---

# 24. Secure Module Distribution

Marketplace থেকে module download করার সময় security গুরুত্বপূর্ণ হবে।

System-এ থাকবে:

* Signed download
* Checksum verification
* Package integrity verification
* Version validation
* Manifest validation
* License validation

Marketplace থেকে আসা module-এর integrity verify না হলে সেটি install করা যাবে না।

---

# 25. ZIP Security

ZIP-based module distribution-এর ক্ষেত্রে বিশেষ security layer থাকবে।

Install করার আগে:

* Path traversal detect করতে হবে
* Unsafe file path reject করতে হবে
* Invalid manifest reject করতে হবে
* Unexpected file structure detect করতে হবে
* Package integrity verify করতে হবে

কোনো malicious package সরাসরি application filesystem-এ extract করা যাবে না।

---

# 26. PHP Code Security

Module host application-এর PHP runtime-এর মধ্যেই execute করবে।

তাই module system-এর security model হবে:

**Installed module = trusted executable application code**

Marketplace-এর জন্য প্রয়োজন হবে:

* Code review
* Package signing
* Hash verification
* Publisher verification
* Release validation

Module sandboxing-এর উপর নির্ভর না করে trusted package distribution model ব্যবহার করা হবে।

---

# 27. Composer Dependency Management

Module-এর নিজস্ব Composer dependency থাকতে পারে।

তবে এটি host application's Composer environment-এর সাথে conflict করতে পারে।

তাই installation-এর আগে:

* Dependency compatibility
* Version conflicts
* Existing package versions
* Required PHP/Laravel versions

check করতে হবে।

Module installation যেন host application-এর existing dependency environment নষ্ট না করে, সেটি একটি গুরুত্বপূর্ণ requirement।

---

# 28. Multi-Tenant Support

Bedrock future-ready হতে হবে।

একই application-এ:

* Global module
* Tenant-specific module

দুটোই support করার architecture রাখতে হবে।

অর্থাৎ ভবিষ্যতে একটি module:

* পুরো application-এর জন্য enabled হতে পারে
* নির্দিষ্ট tenant-এর জন্য enabled হতে পারে
* নির্দিষ্ট tenant-এর জন্য disabled হতে পারে

এই capability architecture-এর শুরু থেকেই মাথায় রাখতে হবে।

---

# 29. Admin Panel

Core Module-এর সাথে একটি built-in module management interface থাকবে।

Admin panel থেকে:

### Module Marketplace

* Browse modules
* Search
* Filter
* View details
* View version
* View changelog
* Install

### Installed Modules

* Installed modules list
* Current version
* Status
* Enable
* Disable
* Update
* Uninstall

### Installation

Installation progress:

**Downloading → Installing → Migrating → Building → Completing**

দেখানো হবে।

Core Module-এর admin interface host application-এর default module management experience হিসেবে কাজ করবে।

---

# 30. Module Developer Experience

Bedrock শুধু end-user-এর জন্য নয়; module developer-এর জন্যও complete ecosystem হবে।

একটি নতুন module তৈরি করার জন্য generator থাকবে।

Generator automatically তৈরি করবে:

* Module structure
* Manifest
* Service provider
* Backend structure
* Frontend structure
* Routes
* Migrations
* Config
* Settings
* Permissions
* Frontend entry
* Build configuration

এর ফলে প্রতিটি নতুন module-এর জন্য একই boilerplate manually তৈরি করতে হবে না।

---

# 31. Module Events

Module lifecycle event-driven হবে।

গুরুত্বপূর্ণ events:

* Module discovered
* Module installing
* Module installed
* Module enabling
* Module enabled
* Module disabling
* Module disabled
* Module uninstalling
* Module uninstalled
* Module updated
* Module installation failed

অন্য module বা Core system প্রয়োজন অনুযায়ী এসব event listen করতে পারবে।

---

# 32. Update System

Marketplace থেকে installed module-এর নতুন version পাওয়া গেলে system update support করবে।

Update lifecycle হবে:

**Detect → Compatibility Check → Backup → Download → Verify → Update → Migrate → Build → Activate**

Update failure হলে recovery/rollback strategy থাকতে হবে।

---

# 33. Backup & Rollback

Production module operation-এর আগে optional database snapshot/backup capability থাকবে।

বিশেষ করে:

* Installation
* Update
* Uninstall

এর আগে backup নেওয়ার option রাখা হবে।

Migration বা frontend build failure হলে moduleকে inconsistent state-এ না রেখে failed state বা previous version-এ ফিরে যাওয়ার ব্যবস্থা থাকতে হবে।

---

# 34. Module State Management

Module-এর state শুধু filesystem-এর উপর নির্ভর করবে না।

Central registry এবং actual filesystem state দুটোই validate করতে হবে।

System detect করতে পারবে:

* Registry আছে কিন্তু files নেই
* Files আছে কিন্তু registry নেই
* Installed version mismatch
* Broken dependency
* Missing frontend build
* Failed installation

এগুলোকে broken/failed module state হিসেবে handle করতে হবে।

---

# 35. Bedrock-এর প্রধান Components

পুরো system-কে conceptualভাবে নিচের components-এ ভাগ করা হবে:

### Core Infrastructure

* Module Manager
* Module Registry
* Module Installer
* Module Uninstaller
* Dependency Resolver
* Lifecycle Manager

### Backend

* Service Provider system
* Routes
* Permissions
* Settings
* Events
* Database management

### Frontend

* Module Registry
* Dynamic loader
* Inertia resolver
* Page loader
* Asset management
* Module boundaries

### Distribution

* Module downloader
* Package validator
* Package integrity verification
* Marketplace client

### Marketplace

* Catalog
* Releases
* Downloads
* Licensing
* Purchases
* Publisher system

### Administration

* Module browser
* Installed module manager
* Installation progress
* Settings
* License management

### Security

* ZIP validation
* Signature/hash verification
* License verification
* Dependency validation
* Package trust model

---

# 36. Development Roadmap

যেহেতু Bedrock শুরু থেকেই complete এবং advanced architecture হিসেবে তৈরি হবে, development-এর order হবে architecture-driven।

### Phase 1 — Core Foundation

* Core Module
* Module contracts
* Module manifest
* Module registry
* Database structure
* Module lifecycle
* Module discovery

### Phase 2 — Module Management

* Installation
* Uninstallation
* Enable/disable
* Settings
* Permissions
* Events
* Dependency resolution

### Phase 3 — Developer Experience

* Module generator
* Module scaffolding
* Publishing system
* Reference module
* Developer tooling

### Phase 4 — Frontend Runtime

* Frontend module structure
* Independent module build
* Module asset management
* Runtime registry
* Dynamic loading
* Inertia integration
* Lazy loading

### Phase 5 — Administration

* Module management UI
* Marketplace browser
* Installation progress
* Module settings
* Permission management

### Phase 6 — Marketplace

* Catalog
* Module releases
* Download system
* Licensing
* Purchases
* Signed packages
* Version management

### Phase 7 — Production Hardening

* Security
* Backup
* Rollback
* Dependency conflict handling
* Failed-state recovery
* Integrity verification
* Multi-tenant support

---

# 37. Final Architecture Goal

Bedrock-এর final experience হবে এমন:

**Developer**

একবার reusable module তৈরি করবে।

**Marketplace**

Module-এর distribution, version, license এবং release manage করবে।

**Host Application**

Marketplace থেকে module install করবে।

**Core Module**

Module-এর সম্পূর্ণ lifecycle manage করবে।

**Frontend**

Module প্রয়োজন অনুযায়ী dynamically load করবে।

ফলে নতুন feature যোগ করার জন্য প্রতিবার application-এর মূল architecture পরিবর্তন করতে হবে না।

---

# 38. Final Product Definition

Bedrock হবে শুধু একটি Laravel module loader নয়।

এটি হবে একটি complete:

**Laravel Modular Application Platform**

যার মধ্যে থাকবে:

**Core Infrastructure + Backend Modules + Dynamic Frontend Modules + Dependency System + Lifecycle Management + Marketplace + Licensing + Administration + Security + Update/Rollback + Multi-Tenant Readiness**

এর মূল ধারণা:

> **Build once. Package as a module. Install anywhere. Enable when needed. Update independently.**

এটাই Bedrock-এর complete architectural direction।
