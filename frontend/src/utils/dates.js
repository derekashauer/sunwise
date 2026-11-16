// Date helpers shared across the app.
//
// Two kinds of values come from the API:
// - Plain calendar dates like due_date ("YYYY-MM-DD"). These are days in the
//   user's own calendar, so they must be parsed as LOCAL dates. Never use
//   new Date('YYYY-MM-DD'), which parses as UTC midnight and shows the previous
//   day anywhere west of UTC.
// - Timestamps like completed_at or performed_at ("YYYY-MM-DD HH:MM:SS"). The
//   database stores these in UTC, so they must be parsed as UTC.

const DAY_MS = 86400000

/**
 * Parse a "YYYY-MM-DD" calendar date as local midnight.
 * Returns null for empty or invalid input.
 */
export function parseLocalDate(dateStr) {
  if (!dateStr) return null
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(dateStr))
  if (!match) return null
  const [, y, m, d] = match
  return new Date(Number(y), Number(m) - 1, Number(d))
}

/**
 * Parse a UTC timestamp from the database ("YYYY-MM-DD HH:MM:SS").
 * Also accepts ISO strings that already carry a zone.
 */
export function parseUtc(value) {
  if (!value) return null
  if (value instanceof Date) return value
  let str = String(value).trim()
  if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
    return parseLocalDate(str)
  }
  str = str.replace(' ', 'T')
  if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(str)) {
    str += 'Z'
  }
  const date = new Date(str)
  return isNaN(date.getTime()) ? null : date
}

/** Local midnight for the given date (defaults to now). */
export function startOfDay(date = new Date()) {
  const d = new Date(date)
  d.setHours(0, 0, 0, 0)
  return d
}

/** Today's date in the device's time zone as "YYYY-MM-DD". */
export function todayLocal() {
  return toLocalDateInput(new Date())
}

/** Format a Date as "YYYY-MM-DD" in local time (for <input type="date">). */
export function toLocalDateInput(date = new Date()) {
  const d = new Date(date)
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

/** Add whole days to a local date and return a new Date. */
export function addDays(date, days) {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d
}

/**
 * Whole calendar days from today to the given date.
 * Positive means in the future, negative means in the past.
 */
export function daysFromToday(date) {
  if (!date) return null
  const target = startOfDay(date)
  const today = startOfDay()
  return Math.round((target - today) / DAY_MS)
}

/** Format a date as "Nov 8". */
export function formatShortDate(date) {
  if (!date) return ''
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

/**
 * Describe a date relative to today in plain words:
 * "today", "yesterday", "4 days ago", "tomorrow", "in 3 days".
 */
export function formatRelativeDays(date) {
  const diff = daysFromToday(date)
  if (diff === null) return ''
  if (diff === 0) return 'today'
  if (diff === -1) return 'yesterday'
  if (diff === 1) return 'tomorrow'
  if (diff < 0) return `${Math.abs(diff)} days ago`
  return `in ${diff} days`
}

/**
 * Short "time ago" text for a timestamp: "Just now", "5m ago", "3h ago",
 * "2d ago", then a short date after a week.
 */
export function formatTimeAgo(value) {
  const date = parseUtc(value)
  if (!date) return ''
  const diffMs = Date.now() - date.getTime()
  const mins = Math.floor(diffMs / 60000)
  if (mins < 1) return 'Just now'
  if (mins < 60) return `${mins}m ago`
  const hours = Math.floor(mins / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days < 7) return `${days}d ago`
  return formatShortDate(date)
}

/** "1 day late" / "5 days late". */
export function formatDaysLate(days) {
  const n = Number(days) || 0
  return `${n} day${n === 1 ? '' : 's'} late`
}

/** The device's IANA time zone, e.g. "America/Denver". */
export function deviceTimeZone() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || ''
  } catch (e) {
    return ''
  }
}
