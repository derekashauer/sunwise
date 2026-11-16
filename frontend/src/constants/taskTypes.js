// One place for everything the UI knows about each care task type.

const ICON_BASE = 'https://img.icons8.com/doodle/48'

export const TASK_TYPES = {
  water: { label: 'Water', icon: `${ICON_BASE}/watering-can.png`, order: 1 },
  change_water: { label: 'Change water', icon: `${ICON_BASE}/water.png`, order: 2 },
  mist: { label: 'Mist', icon: `${ICON_BASE}/splash.png`, order: 3 },
  fertilize: { label: 'Fertilize', icon: `${ICON_BASE}/nature-care.png`, order: 4 },
  check: { label: 'Check', icon: `${ICON_BASE}/visible--v1.png`, order: 5 },
  check_roots: { label: 'Check roots', icon: `${ICON_BASE}/soil.png`, order: 6 },
  rotate: { label: 'Rotate', icon: `${ICON_BASE}/rotate.png`, order: 7 },
  trim: { label: 'Trim', icon: `${ICON_BASE}/cut.png`, order: 8 },
  prune: { label: 'Prune', icon: `${ICON_BASE}/cut.png`, order: 8 },
  repot: { label: 'Repot', icon: `${ICON_BASE}/potted-plant.png`, order: 9 },
  pot_up: { label: 'Pot up', icon: `${ICON_BASE}/potted-plant.png`, order: 10 }
}

export const DEFAULT_TASK_ICON = `${ICON_BASE}/todo-list.png`

/** Icon URL for a task type. */
export function getTaskIcon(taskType) {
  return TASK_TYPES[taskType]?.icon || DEFAULT_TASK_ICON
}

/** Human label for a task type, e.g. "check_roots" -> "Check roots". */
export function getTaskLabel(taskType) {
  if (!taskType) return 'Task'
  if (TASK_TYPES[taskType]) return TASK_TYPES[taskType].label
  const text = String(taskType).replace(/_/g, ' ')
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** Sort position for a task type (lower comes first). */
export function getTaskOrder(taskType) {
  return TASK_TYPES[taskType]?.order ?? 99
}

/** Read a task's recurrence interval in days, from interval_days or the recurrence JSON. */
export function getTaskInterval(task) {
  if (!task) return null
  if (task.interval_days) return Number(task.interval_days)
  try {
    const recurrence = typeof task.recurrence === 'string'
      ? JSON.parse(task.recurrence)
      : task.recurrence
    return recurrence?.interval ? Number(recurrence.interval) : null
  } catch (e) {
    return null
  }
}
