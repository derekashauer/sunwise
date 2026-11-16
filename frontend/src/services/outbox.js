// Offline outbox: care actions taken without a connection are stored here and
// sent to the API once the device is back online.
import Dexie from 'dexie'

let db = null

function getDb() {
  if (db) return db
  db = new Dexie('sunwise')
  db.version(1).stores({
    // kind: complete | skip | snooze, taskId lets us cancel an entry on undo
    outbox: '++id, taskId, createdAt'
  })
  return db
}

/**
 * True when an error means the request never reached the server, so it is
 * safe to queue and retry later.
 */
export function isOfflineError(error) {
  if (!error) return false
  if (error.offline) return true
  return !!error.timeout && typeof navigator !== 'undefined' && !navigator.onLine
}

/** Add an action to the outbox. Returns its id, or null when storage isn't available. */
export async function enqueue({ kind, taskId, method = 'POST', endpoint, body = null }) {
  try {
    return await getDb().outbox.add({
      kind,
      taskId,
      method,
      endpoint,
      body,
      createdAt: Date.now()
    })
  } catch (e) {
    console.error('Could not save offline action:', e)
    return null
  }
}

export async function removeEntry(id) {
  try {
    await getDb().outbox.delete(id)
  } catch (e) {
    console.error('Could not remove offline action:', e)
  }
}

export async function listOutbox() {
  try {
    return await getDb().outbox.orderBy('createdAt').toArray()
  } catch (e) {
    return []
  }
}

export async function countOutbox() {
  try {
    return await getDb().outbox.count()
  } catch (e) {
    return 0
  }
}

export async function clearOutbox() {
  try {
    await getDb().outbox.clear()
  } catch (e) {
    // Storage may be unavailable (private mode). Nothing to clear then.
  }
}

let flushing = null

/**
 * Send queued actions in the order they were made.
 * Stops at the first network failure and leaves the rest queued.
 * Actions the server rejects (for example a task that was already done on
 * another device) are dropped so they don't block the queue.
 */
export function flushOutbox(api) {
  if (flushing) return flushing
  flushing = (async () => {
    const result = { sent: 0, failed: 0, remaining: 0 }
    const entries = await listOutbox()
    for (const entry of entries) {
      try {
        const method = entry.method.toLowerCase()
        if (method === 'post') {
          await api.post(entry.endpoint, entry.body)
        } else if (method === 'put') {
          await api.put(entry.endpoint, entry.body)
        } else if (method === 'delete') {
          await api.delete(entry.endpoint)
        }
        await removeEntry(entry.id)
        result.sent++
      } catch (e) {
        if (isOfflineError(e) || e.status === 401) {
          break
        }
        await removeEntry(entry.id)
        result.failed++
      }
    }
    result.remaining = await countOutbox()
    return result
  })()
  return flushing.finally(() => {
    flushing = null
  })
}
