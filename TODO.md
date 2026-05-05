# TODO: Notification System & Mentor Application Enhancements

## Current Status
✅ = COMPLETED | 🔄 = IN PROGRESS | ⏳ = PENDING

---

## 1. Mentor Application Module (Enhancement)

### Form Fields
- [x] Full Name (First, Middle, Last) - Already in entity
- [x] Email Address (validated) - Already in entity  
- [x] Contact Number - Already in entity
- [x] Specialization (dropdown: BSITBA, BSCS, others) - Need dropdown in form
- [x] Years of Experience (optional) - Already in entity
- [x] Current Profession - Already in entity
- [x] Highest Educational Attainment - Already in entity
- [x] Supporting Description (textarea) - Already in entity

### File Upload
- [x] Proof of Expertise Upload - Already in entity (JSON array)
- [x] Accept: .jpg, .png, .pdf - Need validation in form
- [x] Allow multiple file uploads - Need form handling
- [x] File size limit (5MB each) - Need validation

### Editing Capability
- [x] Edit application before submission - Need route/controller

### Application Status
- [x] Status flow: Pending → Approved/Rejected - Already implemented
- [x] Approval valid until specific term/period - Need form field

### ✅ Remove OTP Verification for mentor application
- Need to update MentoringController to remove OTP flow

### SuperAdmin Controls  
- [x] View all mentor applications - Already exists
- [x] Review uploaded files - Need UI
- [x] Approve/Reject applications - Already exists
- [x] Set validity period - Need form input

---

## 2. Facility Reservation Integration

### Notification System for Reservations
- [ ] Add notification trigger on reservation submission
- [ ] Add notification trigger on approval
- [ ] Add notification trigger on rejection

---

## 3. Global Notification System

### Notification UI (Header)
- [ ] Add notification bell icon in header
- [ ] Show red badge (unread count)
- [ ] Click opens dropdown list

### Notification Triggers
- [ ] Mentor Application: submission, approval, rejection ✅ (service ready)
- [ ] Reservation: submission, approval, rejection (need integration)

### Real-Time Behavior
- [ ] On login: show popup notification
- [ ] Play notification sound
- [ ] When new notification arrives: update badge, show toast, play sound

### Notification Content
- [x] Title ✅ (Notification entity ready)
- [x] Message ✅ (ready)
- [x] Module Type (Mentor/Reservation) ✅ (ready)
- [x] Status ✅ (ready)
- [x] Timestamp ✅ (ready)
- [x] Read/Unread state ✅ (ready)

### User-Specific Notifications
- [x] Only owner receives updates ✅ (repo ready)

### Admin Notifications
- [ ] Notify admin on new mentor application
- [ ] Notify admin on new reservation

---

## 4. Backend Requirements

### Database
- [x] Notifications table exists ✅

### API Endpoints
- [x] Fetch notifications ✅ (NotificationController)
- [x] Mark as read ✅
- [x] Mark all as read ✅
- [x] Create/send notification ✅ (NotificationService)

### Real-Time Option
- [ ] WebSockets or polling (fallback)

---

## 5. UX Behavior

### Clicking Notification
- [ ] Mark as read
- [ ] Redirect to related page
- [ ] "Mark all as read" button
- [ ] Clean dropdown UI

---

## Implementation Priority

### Phase 1: Backend Integration (HIGH PRIORITY)
1. Update ReservationController to send notifications on submission/approval/rejection
2. Update SuperAdminReservationController to send notifications on approval/rejection  
3. Remove OTP from mentor application flow
4. Add notification UI in layouts/app.html.twig header

### Phase 2: Frontend UI (MEDIUM PRIORITY)
1. Create notification dropdown component
2. Add notification bell with badge count
3. Add JavaScript for real-time badge updates

### Phase 3: Advanced Features (LOW PRIORITY)
1. Toast/popup notifications
2. Sound alerts
3. WebSocket integration

---

## Files to Modify

1. **src/Controller/ReservationController.php** - Add notification on submit
2. **src/Controller/SuperAdminReservationController.php** - Add notification on approve/reject
3. **src/Controller/MentoringController.php** - Remove OTP, add notifications
4. **templates/layouts/app.html.twig** - Add notification bell UI
5. **assets/app.js** - Add notification JavaScript
6. **templates/mentoring/index.html.twig** - Update form for new fields
