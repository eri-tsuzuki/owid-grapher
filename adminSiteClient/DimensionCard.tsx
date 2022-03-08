import React from "react"
import { observable, computed, action } from "mobx"
import { observer } from "mobx-react"
import { ChartDimension } from "../grapher/chart/ChartDimension.js"
import { ChartEditor } from "./ChartEditor.js"
import {
    Toggle,
    EditableListItem,
    BindAutoString,
    BindAutoFloat,
    ColorBox,
} from "./Forms.js"
import { Link } from "./Link.js"
import { faChevronDown } from "@fortawesome/free-solid-svg-icons/faChevronDown.js"
import { faChevronUp } from "@fortawesome/free-solid-svg-icons/faChevronUp.js"
import { faExchangeAlt } from "@fortawesome/free-solid-svg-icons/faExchangeAlt.js"
import { faTimes } from "@fortawesome/free-solid-svg-icons/faTimes.js"
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome/index.js"
import { OwidTable } from "../coreTable/OwidTable.js"
import { faArrowsAltV } from "@fortawesome/free-solid-svg-icons/faArrowsAltV.js"

@observer
export class DimensionCard extends React.Component<{
    dimension: ChartDimension
    editor: ChartEditor
    onEdit?: () => void
    onRemove?: () => void
    onMouseEnter?: () => void
    onMouseDown?: () => void
}> {
    @observable.ref isExpanded: boolean = false

    @computed get table(): OwidTable {
        return this.props.editor.grapher.table
    }

    @action.bound onToggleExpand() {
        this.isExpanded = !this.isExpanded
    }

    @action.bound onIsProjection(value: boolean) {
        this.props.dimension.display.isProjection = value
        this.updateTables()
    }

    @action.bound onColor(color: string | undefined) {
        this.props.dimension.display.color = color
        this.updateTables()
    }

    @computed get color() {
        return this.props.dimension.column.def.color
    }

    private get tableDisplaySettings() {
        const { tableDisplay } = this.props.dimension.display
        if (!tableDisplay) return
        return (
            <React.Fragment>
                <hr className="ui divider" />
                Table:
                <Toggle
                    label="Hide absolute change column"
                    value={!!tableDisplay.hideAbsoluteChange}
                    onValue={(value) => {
                        tableDisplay.hideAbsoluteChange = value
                        this.updateTables()
                    }}
                />
                <Toggle
                    label="Hide relative change column"
                    value={!!tableDisplay.hideRelativeChange}
                    onValue={(value) => {
                        tableDisplay.hideRelativeChange = value
                        this.updateTables()
                    }}
                />
                <hr className="ui divider" />
            </React.Fragment>
        )
    }

    @action.bound updateTables() {
        const { grapher } = this.props.editor

        grapher.updateAuthoredVersion({
            dimensions: grapher.filledDimensions.map((dim) => dim.toObject()),
        })

        grapher.rebuildInputOwidTable()
    }

    render() {
        const { dimension, editor } = this.props
        const { grapher } = editor
        const { column } = dimension

        return (
            <EditableListItem
                className="DimensionCard draggable"
                onMouseDown={() => this.props.onMouseDown?.()}
                onMouseEnter={() => this.props.onMouseEnter?.()}
            >
                <header>
                    <div>
                        <span
                            className="clickable"
                            onClick={this.onToggleExpand}
                        >
                            <FontAwesomeIcon
                                icon={
                                    this.isExpanded
                                        ? faChevronUp
                                        : faChevronDown
                                }
                            />
                        </span>
                    </div>
                    <div>
                        <FontAwesomeIcon icon={faArrowsAltV} />
                    </div>
                    <ColorBox color={this.color} onColor={this.onColor} />
                    <div>
                        <Link
                            to={`/variables/${dimension.variableId}`}
                            className="dimensionLink"
                            target="_blank"
                        >
                            {column.name}
                        </Link>
                    </div>
                    <div>
                        {this.props.onEdit && (
                            <div
                                className="clickable"
                                onClick={this.props.onEdit}
                            >
                                <FontAwesomeIcon icon={faExchangeAlt} />
                            </div>
                        )}
                        {this.props.onRemove && (
                            <div
                                className="clickable"
                                onClick={this.props.onRemove}
                            >
                                <FontAwesomeIcon icon={faTimes} />
                            </div>
                        )}
                    </div>
                </header>
                {this.isExpanded && (
                    <div>
                        <BindAutoString
                            label="Display name"
                            field="name"
                            store={dimension.display}
                            auto={column.displayName}
                            onBlur={this.updateTables}
                        />
                        <BindAutoString
                            label="Unit of measurement"
                            field="unit"
                            store={dimension.display}
                            auto={column.unit ?? ""}
                            onBlur={this.updateTables}
                        />
                        <BindAutoString
                            label="Short (axis) unit"
                            field="shortUnit"
                            store={dimension.display}
                            auto={column.shortUnit ?? ""}
                            onBlur={this.updateTables}
                        />
                        <BindAutoFloat
                            label="Number of decimal places"
                            field="numDecimalPlaces"
                            store={dimension.display}
                            auto={column.numDecimalPlaces}
                            helpText={`A negative number here will round integers`}
                            onBlur={this.updateTables}
                        />
                        <BindAutoFloat
                            label="Unit conversion factor"
                            field="conversionFactor"
                            store={dimension.display}
                            auto={column.unitConversionFactor}
                            helpText={`Multiply all values by this amount`}
                            onBlur={this.updateTables}
                        />
                        {this.tableDisplaySettings}
                        <BindAutoFloat
                            field="tolerance"
                            store={dimension.display}
                            auto={column.tolerance}
                            onBlur={this.updateTables}
                        />
                        {grapher.isLineChart && (
                            <Toggle
                                label="Is projection"
                                value={column.isProjection}
                                onValue={this.onIsProjection}
                            />
                        )}
                        <hr className="ui divider" />
                    </div>
                )}
            </EditableListItem>
        )
    }
}
