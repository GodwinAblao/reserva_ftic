# Analytics Page CDO Assessment Report

Prepared for: University administrators, department heads, and decision-makers  
Page reviewed: `/analytics`  
Review perspective: Chief Data Officer and Data Analytics professional

## Review Scope and Limitation

The live public URL redirects unauthenticated users to the login page, so this report is based on the implemented Analytics dashboard structure, chart definitions, and available system metrics in the codebase. The recommendations below are written as an executive interpretation guide and can be paired with the current live numbers during presentation.

## Executive Summary

The Analytics page is organized around five management areas:

| Tab | Main Question Answered | Primary Use |
|---|---|---|
| Demand Outlook | What demand is likely to look like soon? | Prepare capacity, staff, and schedules ahead of time. |
| Planning | When and why do users reserve facilities? | Plan operating hours, booking policies, and room readiness. |
| Organizing | Which facilities are heavily used or underused? | Balance facility workload and improve utilization. |
| Event Execution | Which event types succeed or create bottlenecks? | Improve approval flow, event support, and user guidance. |
| Controlling | Are operations healthy and compliant? | Monitor risk, rejection, cancellation, and process discipline. |

The dashboard is most useful when administrators review it regularly, compare patterns across weeks and months, and act on unusual changes early.

## Tab 1: Demand Outlook

Formerly: Forecasting

### Purpose

This tab helps administrators understand likely future reservation demand and whether the available historical data is reliable enough for decision-making.

### Layman's Explanation of Each Chart

| Chart or Panel | What It Shows | Why It Matters | How Administrators Can Use It |
|---|---|---|---|
| Weekly Reservation Demand | The number of reservations per week for a selected month. | It shows short-term demand changes within a month. | Identify busy weeks and prepare rooms, staff, and equipment earlier. |
| Weekly Reservation Demand (All Time) | Past weekly reservations and expected upcoming weekly demand. | It helps administrators see whether future demand may increase, decrease, or remain steady. | Adjust room availability, staffing, and maintenance schedules before demand peaks. |
| Monthly Reservation Demand | Reservation volume by month, including expected future months. | It reveals broader seasonal patterns. | Prepare for high-demand months such as enrollment periods, finals, organization events, or academic deadlines. |
| Actual vs Expected Demand | Compares actual reservation counts against expected counts. | It shows whether the system's demand estimates are tracking reality well. | If estimates are consistently off, review data quality, unusual events, or changes in reservation behavior. |
| Rolling Forecast Accuracy | Shows whether recent demand predictions are becoming more or less accurate. | It helps administrators know if the outlook can be trusted. | Use the outlook more confidently when accuracy improves; investigate when errors rise. |
| Model Accuracy Evaluation | Summarizes how close the expected demand is to actual demand. | It indicates whether the dashboard is reliable for planning. | Treat low-error results as useful planning support; treat high-error results as a warning to verify with manual context. |
| Demand Intelligence Dashboard | Shows demand stability, growth direction, seasonal behavior, peak months, low-demand months, and data quality. | It gives administrators a quick health check of demand behavior and data reliability. | Use it to decide whether to expand availability, promote underused periods, or clean reservation records. |

### Professional Interpretation

The Demand Outlook tab supports proactive management. Instead of reacting only after rooms are fully booked, administrators can anticipate demand and adjust capacity before issues occur.

Key areas to monitor:

- If weekly demand is rising, the institution may need more available time slots, faster approval processing, or temporary staffing support.
- If monthly demand shows seasonal peaks, administrators should prepare resources ahead of known high-demand periods.
- If demand is highly unstable, policies should include buffer capacity and flexible scheduling.
- If data quality is low, the outlook should be treated carefully because incomplete records weaken decision-making.

### Possible Reasons Behind Patterns

- Academic calendar events such as exams, enrollment, orientations, and organization activities.
- Facility-specific popularity due to equipment, location, capacity, or comfort.
- Sudden increases caused by campaigns, student organization activity, or departmental events.
- Demand drops caused by holidays, term breaks, room unavailability, or lack of awareness.
- Poor data quality caused by missing purposes, inconsistent statuses, or delayed updates.

### Recommendations

- Rename the tab to Demand Outlook across the UI and documentation.
- Review demand outlook every week during active academic periods.
- Prepare staffing and facility availability at least two weeks before expected high-demand periods.
- Compare actual reservations against expected demand monthly.
- Improve data entry discipline, especially reservation purpose, facility, status, date, and time.
- Use demand peaks to guide maintenance scheduling during quieter weeks.

### Anomalies to Watch

- Sudden demand spikes without a known academic or institutional reason.
- Forecast accuracy getting worse over several weeks.
- A high-demand month followed by a sharp drop.
- Strong demand growth with no corresponding increase in approved reservations.
- Low data quality scores, especially if many reservations lack purpose or status updates.

## Tab 2: Planning

### Purpose

The Planning tab explains when reservations happen, what types of events drive demand, and which facilities may need attention in the near future.

### Charts and Metrics

| Chart | Professional Interpretation | Decision Use |
|---|---|---|
| Day-of-Week Demand Pattern | Shows which weekdays receive the most reservations. | Assign more staff and support on the busiest days. |
| Event Type Distribution | Shows which event purposes dominate facility use. | Align room setup, equipment, and support services to common event types. |
| Peak Demand Hours | Shows the busiest time slots. | Avoid scheduling maintenance or staff breaks during peak hours. |
| Per-Facility Weekly Outlook | Shows expected reservation demand per facility for the next few weeks. | Prepare high-demand facilities and promote lower-demand alternatives. |
| Planning KPI Cards | Show demand accuracy, volatility, momentum, data quality, and total records. | Judge whether planning decisions are based on reliable and sufficient data. |

### Key Insights to Look For

- A single day dominating reservations may create staffing and room turnover bottlenecks.
- Concentrated demand during specific hours can cause conflicts and approval delays.
- Heavy reliance on one event type may suggest uneven facility access or narrow user awareness.
- Facilities with expected high demand should be prioritized for readiness checks.

### Possible Reasons Behind Patterns

- Student and faculty availability may cluster around class-free hours.
- Organization events may happen after regular class hours.
- Certain facilities may be better known or better equipped.
- Some event types may require specific rooms, making demand less evenly distributed.

### Recommendations

- Create weekday-specific operating plans for the busiest days.
- Encourage off-peak reservations through reminders or booking guidance.
- Publish clearer facility-use recommendations by event type.
- Prepare standard room setups for the most common event categories.
- Use per-facility demand to guide temporary restrictions, alternative room suggestions, and support staffing.

### Anomalies to Watch

- One day or time slot taking a very large share of demand.
- A sudden rise in one event type.
- Facilities forecasted for high demand despite historically low usage.
- High demand with low approval rates, which may indicate a process or capacity issue.

## Tab 3: Organizing

### Purpose

The Organizing tab helps administrators understand how facility workload is distributed and whether room capacity is being used effectively.

### Charts and Metrics

| Chart or Panel | Professional Interpretation | Decision Use |
|---|---|---|
| Capacity Efficiency Analysis | Shows whether facilities are optimal, adequate, underused, or idle. | Identify rooms to promote, repurpose, or protect from overuse. |
| Facility Usage Comparison | Compares reservation counts across facilities. | Balance demand across rooms. |
| Peak Usage Times | Shows the busiest time slots across all facilities. | Schedule room preparation and support around the busiest periods. |
| Facility Utilization Rate | Shows how intensively each facility is used relative to capacity. | Find overused and underused facilities. |
| Peak Usage by Time of Day | Shows daily and hourly usage patterns. | Identify operational pressure points across the week. |
| Facility Usage Summary | Lists total reservations, total attendees, utilization rate, and usage status. | Support management reporting and facility planning. |

### Key Insights to Look For

- A facility with very high usage may need more maintenance, stricter scheduling, or backup room options.
- A facility with very low usage may need better visibility, improved equipment, or policy review.
- Peak usage times can reveal when support staff are most needed.
- Low utilization with high capacity may indicate an opportunity to redirect larger events.

### Possible Reasons Behind Patterns

- Popular facilities may have better equipment, location, seating, or reputation.
- Underused rooms may not be visible to users or may lack needed features.
- Some rooms may be perceived as unsuitable even if they are available.
- Capacity mismatch may occur when small groups reserve large rooms or large groups compete for limited spaces.

### Recommendations

- Promote underused facilities directly in reservation suggestions.
- Create a facility suitability guide based on event size and purpose.
- Prioritize preventive maintenance for heavily used rooms.
- Review whether high-capacity rooms are being reserved for low-attendance events.
- Consider redirecting demand from overloaded rooms to similar alternatives.

### Anomalies to Watch

- One facility having several times more bookings than the least-used facility.
- High utilization with frequent rejections or cancellations.
- Low utilization for rooms that should be strategically important.
- Peak usage concentrated in a small time window across many days.

## Tab 4: Event Execution

### Purpose

The Event Execution tab evaluates how well reservation requests move from submission to approval and which event purposes are most successful.

### Charts and Metrics

| Chart or Panel | Professional Interpretation | Decision Use |
|---|---|---|
| Reservation Approval Pipeline | Shows submitted, approved, pending, rejected, and cancelled reservations. | Identify approval bottlenecks and process health. |
| Event Success by Purpose | Shows approval success rate by event category. | Find event types that need better guidance or policy support. |
| Top Event Types by Volume | Shows the most frequent event categories (reservation purpose). | Plan resources around common activities. |
| Participant Demand Trend | Shows monthly attendee demand. | Estimate crowd size, room capacity needs, and support requirements. |
| Event Execution KPI Cards | Show approval rate, rejection rate, total pipeline, and RSO-related performance. | Track whether reservation operations support event delivery effectively. |

### Key Insights to Look For

- A high pending count may mean decisions are delayed.
- A high rejection rate may indicate unclear requirements or insufficient facility capacity.
- Some event purposes may succeed more often because they match available facilities better.
- Rising participant demand may require larger rooms or stricter capacity planning.

### Possible Reasons Behind Patterns

- Users may submit incomplete requests.
- Some event types may require special facilities or approvals.
- Facility conflicts may be common during peak academic periods.
- Student organizations may cluster events around the same dates.

### Recommendations

- Reduce pending backlog by setting internal approval turnaround targets.
- Add clearer pre-submission guidance for commonly rejected event types.
- Track rejection reasons and convert frequent issues into user-facing instructions.
- Prepare standard approval checklists for recurring event categories.
- Coordinate with student affairs or departments when participant demand rises.

### Anomalies to Watch

- High volume with low approval rate for a specific event type.
- Sudden increase in cancelled events.
- Large participant demand without matching facility capacity.
- Pending requests accumulating faster than approvals.

## Tab 5: Controlling

### Purpose

The Controlling tab monitors operational health, compliance, risk, and whether reservation processes are working as intended.

### Charts and Metrics

| Chart or Panel | Professional Interpretation | Decision Use |
|---|---|---|
| Operational Compliance Scorecard | Combines setup compliance, attendance behavior, and approval efficiency into one health view. | Quickly assess whether operations are stable or need intervention. |
| Status Distribution | Shows the mix of approved, pending, rejected, and cancelled reservations. | Monitor process outcomes and backlog. |
| Facility Utilization Rate | Shows usage intensity by facility. | Identify facilities that need control actions. |
| Rejection Analysis | Shows rejected and cancelled reservation counts. | Identify process failures or user compliance issues. |
| Facility Risk Assessment Matrix | Highlights facilities with higher overload or no-show risk. | Prioritize administrative action by risk level. |

### Key Insights to Look For

- Low setup compliance means users may be booking too close to event dates.
- High no-show or cancellation rates waste facility capacity.
- High rejection counts may point to unclear rules or insufficient capacity.
- High-risk facilities need closer monitoring and contingency planning.

### Possible Reasons Behind Patterns

- Users may not know how early they should reserve.
- High-demand rooms may receive last-minute or conflicting requests.
- Rejections may increase when guidelines are unclear.
- Cancellations may reflect scheduling conflicts, poor reminders, or changing event plans.

### Recommendations

- Enforce or communicate minimum lead-time policies more clearly.
- Send automated reminders before approved events.
- Review cancellation reasons and identify preventable cases.
- Set thresholds for high-risk facilities and require manual review when exceeded.
- Use the Transaction Ledger and reports to audit repeated process issues.

### Anomalies to Watch

- High pending count with low approval rate.
- High cancellation or rejection rate in a specific facility.
- Facilities repeatedly marked high risk.
- Low compliance score despite normal reservation volume.

## Cross-Functional Recommendations

### Facility Utilization

- Balance demand by recommending similar facilities when one room is overloaded.
- Promote underused rooms through the reservation interface.
- Match room size to expected attendance to reduce capacity waste.
- Schedule maintenance during historically low-demand windows.

### Reservation Operations

- Set a target approval turnaround time.
- Monitor pending requests daily during peak periods.
- Standardize rejection reasons so administrators can identify recurring issues.
- Use the Demand Outlook tab before opening or restricting time slots.

### Mentoring Services

The Analytics page primarily focuses on facility reservations, but mentoring affects overall student support demand and administrative workload. Mentoring should be tracked with similar discipline.

Recommendations:

- Add mentoring request volume, pending requests, assigned requests, completed requests, and cancellation rate to future analytics.
- Compare mentoring demand with academic calendar periods to predict when students need more support.
- Track mentor availability against student request volume.
- Monitor request turnaround time so students are not waiting too long for a mentor match.
- Use facility analytics to reserve appropriate rooms for face-to-face mentoring when demand is expected to rise.

### Overall System Performance

- Keep data entry consistent across reservations, mentoring requests, approvals, cancellations, and rejections.
- Review data quality monthly because analytics are only as reliable as the data recorded.
- Use dashboards as decision support, not as a replacement for administrative judgment.
- Pair chart review with operational context: academic calendar, holidays, exams, organization events, and facility maintenance.

## Recommended Presentation Talking Points

- "Demand Outlook" helps us prepare before demand becomes a problem.
- Planning charts show when and why people reserve facilities.
- Organizing charts show whether facilities are being used fairly and efficiently.
- Event Execution charts show how well requests move through the approval process.
- Controlling charts help us detect operational risks early.
- The most important next step is not just viewing charts, but converting insights into scheduling, staffing, policy, and communication actions.

## Suggested Dashboard Language Improvements

| Current Wording | Suggested Wording | Reason |
|---|---|---|
| Forecasting | Demand Outlook | Easier for non-technical users. |
| ARIMA Model Accuracy Evaluation | Demand Estimate Accuracy | Avoids technical model terminology. |
| Rolling Forecast Accuracy | Recent Prediction Accuracy | Easier to understand. |
| Demand Intelligence Dashboard | Demand Health Summary | More executive-friendly. |
| Actual vs ARIMA Forecast | Actual vs Expected Demand | Clearer for administrators. |
| Per-Facility Weekly Forecast | Expected Facility Demand | More action-oriented. |

## Final CDO Assessment

The Analytics page is a strong operational decision-support tool. Its greatest value is helping administrators move from reactive scheduling to proactive planning. The dashboard already covers the most important reservation-management areas: demand, timing, facility load, event outcomes, and operational control.

The main improvement needed is communication. Technical labels should be simplified so administrators can immediately understand what each chart means and what action to take. Renaming "Forecasting" to "Demand Outlook" is the first step toward making the analytics page more executive-friendly and presentation-ready.
