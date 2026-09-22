function number(value) {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

export function normalizePosLine(line = {}) {
  return {
    ...line,
    id: number(line.id),
    productoId: number(line.productoId),
    cantidad: number(line.cantidad),
    precioBruto: number(line.precioBruto),
    precioNeto: number(line.precioNeto),
    nameProd: line.nameProd || 'Producto',
  }
}

export function normalizePosOrder(order = {}) {
  return {
    ...order,
    id: number(order.id),
    noOrder: String(order.noOrder ?? ''),
    estado: number(order.estado),
    total: number(order.total),
    extra: number(order.extra),
    descuento: number(order.descuento),
    tipoPago: number(order.tipoPago),
    details: Array.isArray(order.details) ? order.details.map(normalizePosLine) : [],
  }
}

export function posOrderTotal(order, extra = order?.extra, discount = order?.descuento) {
  const subtotal = (order?.details || []).reduce((sum, line) => sum + number(line.precioBruto) * number(line.cantidad), 0)
  return Math.max(0, subtotal + number(extra) - number(discount))
}

export function normalizePosPage(response = {}) {
  const rows = Array.isArray(response.data) ? response.data.map(normalizePosOrder) : []
  return {
    rows,
    meta: response.meta && typeof response.meta === 'object'
      ? response.meta
      : {
          current_page: Number(response.current_page || 1),
          last_page: Number(response.last_page || 1),
          total: Number(response.total || rows.length),
        },
  }
}

export function paymentLabel(value) {
  return ({ 1: 'Efectivo', 2: 'Tarjeta', 3: 'Transferencia' })[Number(value)] || 'Sin especificar'
}

export function posHistoryStats(rows = []) {
  const total = rows.reduce((sum, order) => sum + number(order.total), 0)
  return {
    count: rows.length,
    total,
    average: rows.length ? total / rows.length : 0,
    cash: rows.filter((order) => number(order.tipoPago) === 1).length,
    card: rows.filter((order) => number(order.tipoPago) === 2).length,
    transfer: rows.filter((order) => number(order.tipoPago) === 3).length,
  }
}
