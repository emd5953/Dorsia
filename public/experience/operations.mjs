import { safeRead, safeSave, dateInZone, addDays, ApiError } from "./core.mjs";

export const serviceStatuses = [
  "confirmed",
  "arrived",
  "seated",
  "completed",
  "cancelled",
];
const transitions = {
  confirmed: ["arrived", "cancelled"],
  arrived: ["seated", "cancelled"],
  seated: ["completed"],
  completed: [],
  cancelled: [],
};
export function createServiceData() {
  const date = dateInZone();
  const guests = [
    [
      "Alex Morgan",
      "AM",
      7,
      "Window table if available.",
      "Anniversary",
      "confirmed",
      2,
      "19:00",
      1,
    ],
    [
      "Jordan Lee",
      "JL",
      3,
      "Shellfish allergy. Confirm preparation with the kitchen.",
      "Dietary note",
      "confirmed",
      4,
      "19:30",
      1,
    ],
    [
      "Sam Rivera",
      "SR",
      12,
      "Prefers a quieter table.",
      "Returning guest",
      "arrived",
      2,
      "18:30",
      2,
    ],
    [
      "Taylor Brooks",
      "TB",
      1,
      "First visit.",
      "First visit",
      "seated",
      2,
      "18:00",
      3,
    ],
    [
      "Casey Ellis",
      "CE",
      5,
      "Celebrating a birthday.",
      "Birthday",
      "confirmed",
      6,
      "20:00",
      4,
    ],
    [
      "Riley Chen",
      "RC",
      9,
      "No preferences on file.",
      "Returning guest",
      "confirmed",
      2,
      "20:30",
      5,
    ],
    [
      "Jamie Park",
      "JP",
      2,
      "Running 15 minutes late; call noted in preview.",
      "Late arrival",
      "confirmed",
      4,
      "19:00",
      6,
    ],
    [
      "Drew Quinn",
      "DQ",
      4,
      "No preferences on file.",
      "Returning guest",
      "completed",
      2,
      "17:30",
      2,
    ],
  ];
  return {
    version: 1,
    date,
    guests: guests.map(
      (
        [name, initials, visits, notes, tag, status, party, time, venue],
        i,
      ) => ({
        id: i + 1,
        name,
        initials,
        visits,
        notes,
        tag,
        status,
        party,
        time,
        venue,
        date,
        table: i === 2 ? "12" : i === 3 ? "08" : "Unassigned",
        minimum: party * 25000,
      }),
    ),
    inventory: [1, 2, 3, 4, 5, 6].flatMap((venue) =>
      [18, 19, 20, 21].map((hour) => ({
        id: `${venue}-${hour}`,
        venue,
        hour,
        date,
        capacity: 8,
        held: hour === 20 ? 1 : 0,
        booked: hour === 19 ? 5 : hour === 20 ? 6 : 2,
        paused: false,
      })),
    ),
    exceptions: [
      {
        id: 1,
        guest: 2,
        title: "Confirm a dietary requirement",
        detail:
          "Check shellfish cross-contact with the kitchen before seating.",
        priority: "High",
        status: "open",
        resolution: "",
      },
      {
        id: 2,
        guest: 7,
        title: "Arrival running behind",
        detail:
          "Guest expects to arrive 15 minutes after the reservation time.",
        priority: "Medium",
        status: "open",
        resolution: "",
      },
      {
        id: 3,
        guest: 5,
        title: "Birthday arrangement",
        detail: "Coordinate a birthday welcome with the host team.",
        priority: "Low",
        status: "open",
        resolution: "",
      },
    ],
    audit: [],
  };
}
export class OperationsStore {
  constructor(storage) {
    this.storage = storage;
    this.key = "dorsia-service-preview-v1";
    const cached = safeRead(storage, this.key, null);
    this.data =
      cached?.version === 1 &&
      cached.date === dateInZone() &&
      Array.isArray(cached.guests)
        ? cached
        : createServiceData();
    this.persistent = true;
  }
  save(action) {
    this.data.audit.unshift({ action, at: new Date().toISOString() });
    this.data.audit = this.data.audit.slice(0, 50);
    this.persistent = safeSave(this.storage, this.key, this.data);
  }
  transition(id, status) {
    const guest = this.data.guests.find((g) => g.id === id);
    if (!guest || !transitions[guest.status]?.includes(status))
      throw new ApiError(
        "invalid_transition",
        "This service status cannot be changed that way.",
      );
    guest.status = status;
    this.save(`${guest.name}: ${status}`);
  }
  updateGuest(id, { notes, table }) {
    const guest = this.data.guests.find((g) => g.id === id);
    if (!guest) throw new ApiError("not_found", "Guest not found.");
    guest.notes = String(notes).slice(0, 1000);
    guest.table = String(table).slice(0, 30) || "Unassigned";
    this.save(`${guest.name}: service notes updated`);
  }
  updateCapacity(id, capacity, paused) {
    const slot = this.data.inventory.find((s) => s.id === id);
    if (
      !slot ||
      !Number.isInteger(capacity) ||
      capacity < slot.booked + slot.held ||
      capacity > 50
    )
      throw new ApiError(
        "invalid_capacity",
        `Capacity must be a whole number between ${slot ? slot.booked + slot.held : 0} and 50.`,
      );
    slot.capacity = capacity;
    slot.paused = paused;
    this.save(
      `Venue ${slot.venue}, ${slot.hour}:00: capacity ${capacity}${paused ? ", paused" : ""}`,
    );
  }
  resolve(id, resolution) {
    const item = this.data.exceptions.find((e) => e.id === id);
    if (!item || item.status !== "open")
      throw new ApiError(
        "invalid_exception",
        "This exception is already resolved.",
      );
    if (resolution.trim().length < 5)
      throw new ApiError(
        "reason_required",
        "Add a resolution of at least 5 characters.",
      );
    item.status = "resolved";
    item.resolution = resolution.trim().slice(0, 1000);
    this.save(`Exception ${id}: ${item.resolution}`);
  }
}
