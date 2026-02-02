const basePricing = {
  tech: 30,
  consult: 50
};

const methodAddons = {
  phone: 10,
  housecall: 30,
  office: 0
};

flatpickr("#appointmentDate", {
  dateFormat: "m/d/Y",
  minDate: "today",
  disable: [
    function(date) {
      return date.getDay() === 0; // disable Sundays
    }
  ]
});

function populateTimeSlots() {
  const timeSelect = document.getElementById("appointmentTime");
  if (!timeSelect) return;

  timeSelect.innerHTML = "";

  const startHour = 12; // 12 PM
  const endHour = 19;   // 7 PM

  function formatAMPM(hour, minutes) {
    let h = hour;
    let suffix = "PM";

    if (hour > 12) h -= 12;
    if (hour === 12) suffix = "PM";

    return `${h}:${minutes.toString().padStart(2, "0")} ${suffix}`;
  }

  for (let hour = startHour; hour <= endHour; hour++) {
    let label1 = formatAMPM(hour, 0);
    let opt1 = document.createElement("option");
    opt1.value = label1;
    opt1.textContent = label1;
    timeSelect.appendChild(opt1);

    if (hour !== endHour) {
      let label2 = formatAMPM(hour, 30);
      let opt2 = document.createElement("option");
      opt2.value = label2;
      opt2.textContent = label2;
      timeSelect.appendChild(opt2);
    }
  }

  let lastOpt = document.createElement("option");
  lastOpt.value = "7:30 PM";
  lastOpt.textContent = "7:30 PM";
  timeSelect.appendChild(lastOpt);
}

function updateMethodLabels() {
  const serviceSelect = document.getElementById("serviceType");
  const methodSelect = document.getElementById("serviceMethod");
  if (!serviceSelect || !methodSelect) return;

  const service = serviceSelect.value;
  methodSelect.innerHTML = "";

  const methods = [
    { value: "phone", label: "Over the Phone" },
    { value: "housecall", label: "Housecall" },
    { value: "office", label: "In Office" }
  ];

  methods.forEach(m => {
    const total = basePricing[service] + methodAddons[m.value];
    const opt = document.createElement("option");
    opt.value = m.value;
    opt.textContent = `${m.label} ($${total}/hr)`;
    methodSelect.appendChild(opt);
  });
}

function updatePrice() {
  const serviceSelect = document.getElementById("serviceType");
  const methodSelect = document.getElementById("serviceMethod");
  const priceDisplay = document.getElementById("priceDisplay");

  if (!serviceSelect || !methodSelect || !priceDisplay) return;

  const base = basePricing[serviceSelect.value] || 0;
  const addon = methodAddons[methodSelect.value] || 0;
  priceDisplay.textContent = `Price: $${base + addon}/hr`;
}

async function fetchBookedTimes(date) {
  const url = kb_ajax.ajax_url + "?action=kb_get_booked_times&date=" + encodeURIComponent(date);
  const response = await fetch(url);
  return await response.json();
}

function convertToMinutes(timeStr) {
  const [time, modifier] = timeStr.split(" ");
  let [hours, minutes] = time.split(":").map(Number);

  if (modifier === "PM" && hours !== 12) hours += 12;
  if (modifier === "AM" && hours === 12) hours = 0;

  return hours * 60 + minutes;
}

function blockTimes(bookedTimes) {
  const timeSelect = document.getElementById("appointmentTime");
  if (!timeSelect) return;

  const options = Array.from(timeSelect.options);

  options.forEach(opt => opt.disabled = false);

  bookedTimes.forEach(time => {
    const booked = convertToMinutes(time);

    options.forEach(opt => {
      const current = convertToMinutes(opt.value);

      if (opt.value === time) opt.disabled = true;
      if (current >= booked - 60 && current < booked) opt.disabled = true;
      if (current > booked && current <= booked + 120) opt.disabled = true;
    });
  });
}

document.addEventListener("DOMContentLoaded", () => {
  populateTimeSlots();
  updateMethodLabels();
  updatePrice();

  const serviceSelect = document.getElementById("serviceType");
  const methodSelect = document.getElementById("serviceMethod");
  const dateInput = document.getElementById("appointmentDate");

  if (serviceSelect) {
    serviceSelect.addEventListener("change", () => {
      updateMethodLabels();
      updatePrice();
    });
  }

  if (methodSelect) {
    methodSelect.addEventListener("change", updatePrice);

    methodSelect.addEventListener("change", () => {
      const addressField = document.getElementById("clientAddress");
      const addressLabel = document.getElementById("addressLabel");

      if (!addressField || !addressLabel) return;

      if (methodSelect.value === "housecall") {
        addressField.style.display = "block";
        addressLabel.style.display = "block";
        addressField.required = true;
      } else {
        addressField.style.display = "none";
        addressLabel.style.display = "none";
        addressField.required = false;
      }
    });
  }

  if (dateInput) {
    dateInput.addEventListener("change", async () => {
      const date = dateInput.value;
      if (!date) return;

      const booked = await fetchBookedTimes(date);
      blockTimes(booked);
    });
  }

  // SIMPLE FORM SUBMISSION (NO PAYMENT)
  const form = document.getElementById("kbSchedulerForm");
  if (form) {
    form.addEventListener("submit", async function(e) {
      e.preventDefault();

      const status = document.getElementById("schedulerStatus");

      const payload = {
        action: "kb_create_payment",
        service_type: document.getElementById("serviceType").value,
        service_method: document.getElementById("serviceMethod").value,
        appointment_date: document.getElementById("appointmentDate").value,
        appointment_time: document.getElementById("appointmentTime").value,
        client_name: document.getElementById("clientName").value,
        client_email: document.getElementById("clientEmail").value,
        client_address: document.getElementById("clientAddress").value
      };

      if (status) {
        status.textContent = "Booking appointment...";
        status.style.color = "blue";
      }

      try {
        const response = await fetch(kb_ajax.ajax_url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });

        const result = await response.json();

        // 🔥 FIX: Guarantee readable error message
        if (!result.success) {
          const msg = result.message || "An unknown error occurred.";
          if (status) {
            status.textContent = "Error: " + msg;
            status.style.color = "red";
          }
          return;
        }

        if (status) {
          status.textContent = "Appointment booked successfully.";
          status.style.color = "green";
        }

      } catch (err) {
        console.error("Scheduler error:", err);
        if (status) {
          status.textContent = "Unexpected error while booking appointment.";
          status.style.color = "red";
        }
      }
    });
  }
});